<?php
declare(strict_types=1);

/**
 * SlotMap — 16384 個 hash slot 的「slot → 節點」映射表（仿 Redis Cluster）
 * ---------------------------------------------------------------------
 * 真實 Redis Cluster：固定 16384 個 hash slot，每個 master 負責一段 slot 區間；
 *   slot = crc16(key) % 16384。加/移除節點時，叢集把若干 slot「搬移」到別的節點，
 *   只搬移受影響的 slot（不像傳統取模 N 那樣全表重排）。
 *
 * 本檔案是「真實邏輯」：
 *   - 固定 16384 個 slot
 *   - slot(key) = crc32(key) % 16384  ← 誠實聲明：用 crc32 近似 crc16（環境禁用會產生 crc16 的擴充）
 *   - slot → node 的完整映射；以「連續區段」記錄哪些 slot 屬於哪個 node（省記憶體、好展示）
 *   - 加節點：從現有節點勻出一批 slot 給新節點（展示哪些 slot 換主）
 *   - 移除節點：把它負責的 slot 併回其他節點（展示哪些 slot 換主）
 *
 * 共享狀態存 data/slotmap.json，所有寫入用 flock 保護。
 */
final class SlotMap
{
    public const SLOT_COUNT = 16384;

    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = rtrim($dataDir, '/\\') . '/slotmap.json';
        if (!file_exists($this->file)) {
            // 初始三節點，平均分配 16384 個 slot
            $this->writeState($this->buildInitial(['shardA', 'shardB', 'shardC']));
        }
    }

    /**
     * 計算 key 落在哪個 slot。
     * 誠實聲明：Redis Cluster 用 crc16；本環境禁用相關擴充，改用 crc32 % 16384 近似，
     *           兩者皆為「同 key 必同 slot、分佈均勻」的雜湊取模，路由邏輯完全相同。
     */
    public static function slotOf(string $key): int
    {
        // 支援 hash tag：key 內含 {...} 時只對大括號內容雜湊（與 Redis 行為一致，
        // 可讓相關 key 落同一 slot，方便同 slot 內做多鍵操作）
        if (preg_match('/\{([^}]+)\}/', $key, $m) && $m[1] !== '') {
            $key = $m[1];
        }
        return crc32($key) % self::SLOT_COUNT;
    }

    /** 取得某 key 該打到哪個節點 */
    public function nodeForKey(string $key): string
    {
        return $this->nodeForSlot(self::slotOf($key));
    }

    /** slot → 節點 */
    public function nodeForSlot(int $slot): string
    {
        $state = $this->readState();
        foreach ($state['ranges'] as $r) {
            if ($slot >= $r['start'] && $slot <= $r['end']) {
                return $r['node'];
            }
        }
        // 理論上不會走到（16384 個 slot 必被某區段覆蓋）
        return $state['nodes'][0] ?? 'shardA';
    }

    /** @return list<string> 目前所有節點 */
    public function nodes(): array
    {
        return $this->readState()['nodes'];
    }

    /**
     * 取得每個節點負責的 slot 區段與數量（給前端展示用）。
     * @return list<array{node:string,start:int,end:int,count:int}>
     */
    public function ranges(): array
    {
        $out = [];
        foreach ($this->readState()['ranges'] as $r) {
            $out[] = [
                'node' => $r['node'],
                'start' => $r['start'],
                'end' => $r['end'],
                'count' => $r['end'] - $r['start'] + 1,
            ];
        }
        return $out;
    }

    /**
     * 加入一個新節點：從現有節點各勻出尾段 slot 給新節點，盡量平均。
     * 回傳「被搬移的 slot 區段」清單，展示哪些 slot 換了主。
     * @return array{added:string,moved:list<array{from:string,start:int,end:int,count:int}>}
     */
    public function addNode(string $node): array
    {
        $node = $this->safeName($node);
        $fp = $this->lock();
        try {
            $state = $this->readStateLocked($fp);
            if (in_array($node, $state['nodes'], true)) {
                return ['added' => $node, 'moved' => [], 'note' => '節點已存在'];
            }

            $newCount = count($state['nodes']) + 1;
            $target = intdiv(self::SLOT_COUNT, $newCount); // 新節點目標 slot 數

            // 從每個現有節點的「尾段」勻出 slot，湊滿 target
            $moved = [];
            $need = $target;
            // 由 slot 數最多的節點先勻（穩定：依 count 由大到小）
            usort($state['ranges'], static fn($a, $b) =>
                ($b['end'] - $b['start']) <=> ($a['end'] - $a['start']));

            $newRanges = [];
            foreach ($state['ranges'] as $r) {
                $size = $r['end'] - $r['start'] + 1;
                if ($need > 0 && $size > 1) {
                    $take = min($need, $size - 1); // 至少給原節點留 1 個 slot
                    $newRanges[] = ['node' => $r['node'], 'start' => $r['start'], 'end' => $r['end'] - $take];
                    $moveStart = $r['end'] - $take + 1;
                    $moved[] = [
                        'from' => $r['node'],
                        'start' => $moveStart,
                        'end' => $r['end'],
                        'count' => $take,
                    ];
                    $newRanges[] = ['node' => $node, 'start' => $moveStart, 'end' => $r['end']];
                    $need -= $take;
                } else {
                    $newRanges[] = $r;
                }
            }

            $state['nodes'][] = $node;
            $state['ranges'] = $this->normalize($newRanges);
            $this->writeStateLocked($fp, $state);

            return ['added' => $node, 'moved' => $moved];
        } finally {
            $this->unlock($fp);
        }
    }

    /**
     * 移除一個節點：把它負責的 slot 併回其他節點（平均分給剩餘節點）。
     * 回傳被搬移的 slot 區段清單。
     * @return array{removed:string,moved:list<array{to:string,start:int,end:int,count:int}>}
     */
    public function removeNode(string $node): array
    {
        $node = $this->safeName($node);
        $fp = $this->lock();
        try {
            $state = $this->readStateLocked($fp);
            if (!in_array($node, $state['nodes'], true)) {
                return ['removed' => $node, 'moved' => [], 'note' => '節點不存在'];
            }
            if (count($state['nodes']) <= 1) {
                return ['removed' => $node, 'moved' => [], 'note' => '至少需保留一個節點'];
            }

            $remain = array_values(array_filter($state['nodes'], static fn($n) => $n !== $node));

            // 收集被移除節點的所有 slot 區段
            $orphan = [];
            $kept = [];
            foreach ($state['ranges'] as $r) {
                if ($r['node'] === $node) {
                    $orphan[] = $r;
                } else {
                    $kept[] = $r;
                }
            }

            // 把孤兒 slot 輪流分給剩餘節點
            $moved = [];
            $i = 0;
            foreach ($orphan as $r) {
                $to = $remain[$i % count($remain)];
                $kept[] = ['node' => $to, 'start' => $r['start'], 'end' => $r['end']];
                $moved[] = [
                    'to' => $to,
                    'start' => $r['start'],
                    'end' => $r['end'],
                    'count' => $r['end'] - $r['start'] + 1,
                ];
                $i++;
            }

            $state['nodes'] = $remain;
            $state['ranges'] = $this->normalize($kept);
            $this->writeStateLocked($fp, $state);

            return ['removed' => $node, 'moved' => $moved];
        } finally {
            $this->unlock($fp);
        }
    }

    // ============ 內部：狀態建構與正規化 ============

    /** @param list<string> $nodes */
    private function buildInitial(array $nodes): array
    {
        $n = count($nodes);
        $per = intdiv(self::SLOT_COUNT, $n);
        $ranges = [];
        $cursor = 0;
        for ($i = 0; $i < $n; $i++) {
            $end = ($i === $n - 1) ? self::SLOT_COUNT - 1 : $cursor + $per - 1;
            $ranges[] = ['node' => $nodes[$i], 'start' => $cursor, 'end' => $end];
            $cursor = $end + 1;
        }
        return ['nodes' => $nodes, 'ranges' => $ranges];
    }

    /**
     * 正規化區段：依 start 排序，合併相鄰且同節點的區段，確保覆蓋 0..16383 無縫無重疊。
     * @param list<array{node:string,start:int,end:int}> $ranges
     * @return list<array{node:string,start:int,end:int}>
     */
    private function normalize(array $ranges): array
    {
        usort($ranges, static fn($a, $b) => $a['start'] <=> $b['start']);
        $out = [];
        foreach ($ranges as $r) {
            $last = $out[count($out) - 1] ?? null;
            if ($last !== null && $last['node'] === $r['node'] && $r['start'] === $last['end'] + 1) {
                $out[count($out) - 1]['end'] = $r['end'];
            } else {
                $out[] = $r;
            }
        }
        return $out;
    }

    // ============ 持久化（flock 保護） ============

    private function lock()
    {
        $fp = fopen($this->file, 'c+');
        if ($fp === false) {
            throw new RuntimeException('無法開啟 slotmap 檔');
        }
        flock($fp, LOCK_EX);
        return $fp;
    }

    private function unlock($fp): void
    {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function readState(): array
    {
        $json = (string) @file_get_contents($this->file);
        $arr = json_decode($json, true);
        if (!is_array($arr) || !isset($arr['nodes'], $arr['ranges'])) {
            return $this->buildInitial(['shardA', 'shardB', 'shardC']);
        }
        return $arr;
    }

    /** 從已持有鎖的檔案指標讀（呼叫端在 lock() 之後） */
    private function readStateLocked($fp): array
    {
        rewind($fp);
        $raw = '';
        while (($chunk = fread($fp, 8192)) !== false && $chunk !== '') {
            $raw .= $chunk;
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr) || !isset($arr['nodes'], $arr['ranges'])) {
            return $this->buildInitial(['shardA', 'shardB', 'shardC']);
        }
        return $arr;
    }

    private function writeState(array $state): void
    {
        file_put_contents(
            $this->file,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * 寫回已持有鎖的檔案指標（truncate + rewind + write）。
     * Windows 上不能對仍被 flock 開啟的檔案做第二次開檔/rename，故直接寫回同一指標。
     */
    private function writeStateLocked($fp, array $state): void
    {
        $json = (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^0-9A-Za-z_\-]/', '', $name) ?? '';
        return $name === '' ? 'node' : $name;
    }
}
