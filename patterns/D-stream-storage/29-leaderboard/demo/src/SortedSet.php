<?php
declare(strict_types=1);

/**
 * SortedSet — 模擬 Redis ZSET 語意（教學版）
 * ------------------------------------------------------------------
 * 排行榜的核心資料結構就是「有序集合」(sorted set)。
 * Redis 的 ZSET 內部用 skiplist + hash table，zadd/zrank/zrange 都是 O(log N)；
 * 本 demo 為了零依賴，改用「PHP 陣列 + 每次更新後排序」實作，
 * 語意（更新即重排、Top-K、名次、名次±鄰居、同分 tie-break、分窗榜）完全為真，
 * 但複雜度退化成 O(N log N)（見 §效能備註與 notes 第 9 段「誠實聲明」）。
 *
 * 排名約定：本實作 rank 由 1 起算（第 1 名 rank = 1），更貼近玩家直覺。
 * 同分 tie-break：分數相同時，先加入者排前面（用單調遞增的 seq 當次序），
 *   等同 Redis 對同分成員「以插入順序穩定排序」的近似（Redis 實際以 member 字典序，
 *   本 demo 採插入順序，差異已於此註明）。
 *
 * 一個 SortedSet 實例 = 一個榜 key（daily / weekly / all 各一份檔案）。
 * 持久化：成員→分數 與 成員→seq 兩張表，落地到 data/zset_<board>.json，
 *   多進程共享狀態以 flock 保護（讀改寫整段上鎖）。
 */
final class SortedSet
{
    private string $file;

    public function __construct(string $dataDir, string $board)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        // board 已在路由層白名單化，這裡再防一次路徑穿越
        $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($board));
        $this->file = $dataDir . '/zset_' . $safe . '.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode($this->emptyState()));
        }
    }

    /**
     * ZADD：新增或更新成員分數。
     * 同名成員只保留一筆；更新分數後整體重排（呈現「更新即重排」語意）。
     * 回傳該成員更新後的名次（1 起算）。
     */
    public function zadd(string $member, float $score): int
    {
        return $this->withState(function (array &$state) use ($member, $score): int {
            $isNew = !isset($state['scores'][$member]);
            $state['scores'][$member] = $score;
            if ($isNew) {
                // 首次加入才配發 seq；更新既有成員不改變其插入序（同分 tie-break 穩定）
                $state['seq'][$member] = $state['nextSeq'];
                $state['nextSeq']++;
            }
            $ordered = $this->order($state);
            return $this->rankOf($ordered, $member);
        });
    }

    /** ZSCORE：取某成員分數，不存在回 null。 */
    public function zscore(string $member): ?float
    {
        $state = $this->read();
        return isset($state['scores'][$member]) ? (float) $state['scores'][$member] : null;
    }

    /**
     * ZREVRANK：由高到低的名次（1 起算）。不存在回 null。
     * （Redis 原生 zrevrank 由 0 起算；本 demo 統一改為 1 起算，較貼近玩家直覺。）
     */
    public function zrevrank(string $member): ?int
    {
        $state = $this->read();
        if (!isset($state['scores'][$member])) {
            return null;
        }
        return $this->rankOf($this->order($state), $member);
    }

    /**
     * ZREVRANGE(0, k-1)：Top-K（分數由高到低）。
     * 回傳 [['rank'=>1,'member'=>..,'score'=>..], ...]。
     */
    public function topK(int $k): array
    {
        $state = $this->read();
        $ordered = $this->order($state);
        $out = [];
        $n = min($k, count($ordered));
        for ($i = 0; $i < $n; $i++) {
            $m = $ordered[$i];
            $out[] = ['rank' => $i + 1, 'member' => $m, 'score' => (float) $state['scores'][$m]];
        }
        return $out;
    }

    /**
     * 我的名次 ± 鄰居：給 member，回傳其前後各 n 名的視窗
     * （排行榜常見「你在第 1234 名」+ 上下鄰居）。
     * 回傳 ['rank'=>該成員名次,'total'=>總人數,'window'=>[...含自己...]]，不存在回 null。
     */
    public function around(string $member, int $n): ?array
    {
        $state = $this->read();
        if (!isset($state['scores'][$member])) {
            return null;
        }
        $ordered = $this->order($state);
        $idx = array_search($member, $ordered, true);   // 0 起算的位置
        $total = count($ordered);
        $start = max(0, $idx - $n);
        $end = min($total - 1, $idx + $n);
        $window = [];
        for ($i = $start; $i <= $end; $i++) {
            $m = $ordered[$i];
            $window[] = [
                'rank' => $i + 1,
                'member' => $m,
                'score' => (float) $state['scores'][$m],
                'me' => ($m === $member),
            ];
        }
        return ['rank' => $idx + 1, 'total' => $total, 'window' => $window];
    }

    /** 目前榜上總人數。 */
    public function count(): int
    {
        return count($this->read()['scores']);
    }

    // ============ 內部：排序 / 名次 / 持久化 ============

    /**
     * 回傳排好序的 member 陣列（由高到低；同分時先加入者在前）。
     * 真實 Redis 用 skiplist 維護有序性，O(log N) 插入即有序；
     * 本 demo 每次查詢/更新都重排 → O(N log N)，僅供教學。
     */
    private function order(array $state): array
    {
        $members = array_keys($state['scores']);
        usort($members, function (string $a, string $b) use ($state): int {
            $sa = (float) $state['scores'][$a];
            $sb = (float) $state['scores'][$b];
            if ($sa !== $sb) {
                return $sb <=> $sa;                       // 分數高者在前
            }
            return $state['seq'][$a] <=> $state['seq'][$b]; // 同分：先加入者在前
        });
        return $members;
    }

    private function rankOf(array $ordered, string $member): int
    {
        $idx = array_search($member, $ordered, true);
        return $idx === false ? 0 : $idx + 1;
    }

    private function emptyState(): array
    {
        return ['scores' => new \stdClass(), 'seq' => new \stdClass(), 'nextSeq' => 1];
    }

    /** 讀取狀態（不上鎖，供唯讀查詢用）。 */
    private function read(): array
    {
        $json = (string) file_get_contents($this->file);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['scores' => [], 'seq' => [], 'nextSeq' => 1];
        }
        $data['scores'] = $data['scores'] ?? [];
        $data['seq'] = $data['seq'] ?? [];
        $data['nextSeq'] = $data['nextSeq'] ?? 1;
        return $data;
    }

    /**
     * 讀改寫整段上鎖（flock LOCK_EX），確保並發 zadd 不互相覆蓋。
     * 回呼可修改 $state（傳參考）並回傳值；本方法負責持久化與回傳。
     */
    private function withState(callable $fn): mixed
    {
        $fp = fopen($this->file, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('無法開啟榜檔：' . $this->file);
        }
        try {
            flock($fp, LOCK_EX);
            $raw = '';
            while (!feof($fp)) {
                $chunk = fread($fp, 8192);
                if ($chunk === false) {
                    break;
                }
                $raw .= $chunk;
            }
            $state = json_decode($raw, true);
            if (!is_array($state)) {
                $state = ['scores' => [], 'seq' => [], 'nextSeq' => 1];
            }
            $state['scores'] = $state['scores'] ?? [];
            $state['seq'] = $state['seq'] ?? [];
            $state['nextSeq'] = $state['nextSeq'] ?? 1;

            $result = $fn($state);

            $payload = json_encode([
                'scores' => empty($state['scores']) ? new \stdClass() : $state['scores'],
                'seq' => empty($state['seq']) ? new \stdClass() : $state['seq'],
                'nextSeq' => $state['nextSeq'],
            ], JSON_UNESCAPED_UNICODE);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) $payload);
            fflush($fp);
            return $result;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
