<?php
declare(strict_types=1);

/**
 * 分散式訊息佇列 Broker（教學版，單機檔案模擬 Kafka 核心邏輯）
 * ---------------------------------------------------------------
 * 真實 Kafka：多 broker 叢集、分區有副本(ISR)、走網路協定、ZooKeeper/KRaft 協調。
 * 這裡用「每分區一個 append-only log 檔」+「消費者組 offset 表」模擬，
 * 但以下邏輯都是真的、可實際執行：
 *   - 依 key 分區：partition = crc32(key) % N（同 key 必同分區 → 分區內有序）
 *   - 每分區單調遞增 offset
 *   - 消費者組 (group,topic,partition) 各自記 committed offset
 *   - poll 回傳 offset 之後的訊息、commit 推進 offset
 *   - replay：把某 group 的 offset seek 回 0（或指定位移）重新消費
 *   - rebalance：把分區指派給組內多個 consumer（range / round-robin）
 *
 * 所有寫入用 flock 保護，支援多請求併發。
 */
final class Broker
{
    private string $dataDir;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dataDir = rtrim($dataDir, '/\\');
    }

    // ============ 主題 / 分區 ============

    /** 主題中繼資料檔（記錄每個 topic 有幾個分區） */
    private function topicsFile(): string
    {
        return $this->dataDir . '/topics.json';
    }

    /** @return array<string,int> topic => 分區數 */
    private function readTopics(): array
    {
        $f = $this->topicsFile();
        if (!file_exists($f)) {
            return [];
        }
        $json = (string) file_get_contents($f);
        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }

    private function writeTopics(array $topics): void
    {
        file_put_contents(
            $this->topicsFile(),
            json_encode($topics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /** 建立主題（指定分區數 N）；已存在則不改分區數 */
    public function createTopic(string $topic, int $partitions): void
    {
        $topic = $this->safeName($topic);
        if ($partitions < 1) {
            $partitions = 1;
        }
        $topics = $this->readTopics();
        if (!isset($topics[$topic])) {
            $topics[$topic] = $partitions;
            $this->writeTopics($topics);
        }
    }

    public function partitionCount(string $topic): int
    {
        $topic = $this->safeName($topic);
        $topics = $this->readTopics();
        return $topics[$topic] ?? 0;
    }

    /** @return array<string,int> 所有 topic => 分區數 */
    public function topics(): array
    {
        return $this->readTopics();
    }

    /** 依 key 決定分區：crc32(key) % N。無 key 時退化為輪詢/隨機（這裡用 0 起遞增不易，故落 0）。 */
    public function partitionFor(string $topic, ?string $key): int
    {
        $n = $this->partitionCount($topic);
        if ($n < 1) {
            return 0;
        }
        if ($key === null || $key === '') {
            // 無 key：真實 Kafka 走 sticky/round-robin；此處簡化為均分到分區 0
            return 0;
        }
        return crc32($key) % $n;
    }

    // ============ 分區 log（append-only） ============

    private function partitionFile(string $topic, int $partition): string
    {
        $topic = $this->safeName($topic);
        return $this->dataDir . "/log_{$topic}_p{$partition}.ndjson";
    }

    /**
     * produce：依 key 分區，append 到該分區 log，配單調遞增 offset。
     * @return array{topic:string,partition:int,offset:int,key:?string,value:mixed,ts:string}
     */
    public function produce(string $topic, ?string $key, mixed $value): array
    {
        $topic = $this->safeName($topic);
        if ($this->partitionCount($topic) < 1) {
            // 主題不存在 → 預設建 3 個分區（與測試頁預設一致）
            $this->createTopic($topic, 3);
        }
        $partition = $this->partitionFor($topic, $key);
        $file = $this->partitionFile($topic, $partition);

        $fp = fopen($file, 'c+');
        if ($fp === false) {
            throw new RuntimeException("無法開啟分區 log: $file");
        }
        flock($fp, LOCK_EX);

        // offset = 該分區目前訊息數（檔案行數）
        $offset = $this->countLines($fp);
        $record = [
            'offset' => $offset,
            'key' => $key,
            'value' => $value,
            'ts' => gmdate('c'),
        ];
        fseek($fp, 0, SEEK_END);
        fwrite($fp, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return [
            'topic' => $topic,
            'partition' => $partition,
            'offset' => $offset,
            'key' => $key,
            'value' => $value,
            'ts' => $record['ts'],
        ];
    }

    /** 讀某分區 offset >= $from 的所有訊息 */
    public function readPartition(string $topic, int $partition, int $from = 0): array
    {
        $file = $this->partitionFile($topic, $partition);
        if (!file_exists($file)) {
            return [];
        }
        $out = [];
        $fp = fopen($file, 'r');
        if ($fp === false) {
            return [];
        }
        flock($fp, LOCK_SH);
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $rec = json_decode($line, true);
            if (is_array($rec) && ($rec['offset'] ?? -1) >= $from) {
                $out[] = $rec;
            }
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return $out;
    }

    /** 某分區目前的 log 結束位置（= 訊息總數，亦即下一個 offset） */
    public function logEndOffset(string $topic, int $partition): int
    {
        $file = $this->partitionFile($topic, $partition);
        if (!file_exists($file)) {
            return 0;
        }
        $fp = fopen($file, 'r');
        if ($fp === false) {
            return 0;
        }
        flock($fp, LOCK_SH);
        $n = $this->countLines($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $n;
    }

    // ============ 消費者組 offset 表 ============

    private function offsetsFile(): string
    {
        return $this->dataDir . '/offsets.json';
    }

    /** @return array<string,int> key "group|topic|partition" => committed offset */
    private function readOffsets(): array
    {
        $f = $this->offsetsFile();
        if (!file_exists($f)) {
            return [];
        }
        $arr = json_decode((string) file_get_contents($f), true);
        return is_array($arr) ? $arr : [];
    }

    private function writeOffsets(array $offsets): void
    {
        file_put_contents(
            $this->offsetsFile(),
            json_encode($offsets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function offsetKey(string $group, string $topic, int $partition): string
    {
        return $this->safeName($group) . '|' . $this->safeName($topic) . '|' . $partition;
    }

    public function committedOffset(string $group, string $topic, int $partition): int
    {
        $offsets = $this->readOffsets();
        return $offsets[$this->offsetKey($group, $topic, $partition)] ?? 0;
    }

    /**
     * poll：回傳某 group 在某 topic 各分區 committed offset 之後的新訊息。
     * 不自動 commit（at-least-once：先處理再 commit）。
     * @return array{messages:list<array>, nextOffsets:array<int,int>}
     */
    public function poll(string $group, string $topic, int $maxPerPartition = 100): array
    {
        $topic = $this->safeName($topic);
        $n = $this->partitionCount($topic);
        $messages = [];
        $nextOffsets = [];
        for ($p = 0; $p < $n; $p++) {
            $committed = $this->committedOffset($group, $topic, $p);
            $recs = $this->readPartition($topic, $p, $committed);
            $taken = 0;
            $next = $committed;
            foreach ($recs as $r) {
                if ($taken >= $maxPerPartition) {
                    break;
                }
                $messages[] = [
                    'partition' => $p,
                    'offset' => $r['offset'],
                    'key' => $r['key'] ?? null,
                    'value' => $r['value'] ?? null,
                    'ts' => $r['ts'] ?? null,
                ];
                $next = $r['offset'] + 1; // 處理到此筆 → 下一個要 commit 的 offset
                $taken++;
            }
            $nextOffsets[$p] = $next;
        }
        return ['messages' => $messages, 'nextOffsets' => $nextOffsets];
    }

    /** commit：推進某 group 在各分區的 committed offset */
    public function commit(string $group, string $topic, array $partitionOffsets): void
    {
        $topic = $this->safeName($topic);
        $offsets = $this->readOffsets();
        foreach ($partitionOffsets as $p => $off) {
            $offsets[$this->offsetKey($group, $topic, (int) $p)] = (int) $off;
        }
        $this->writeOffsets($offsets);
    }

    /**
     * seek / replay：把某 group 在某 topic 所有分區的 offset 設回 $to（預設 0）。
     * → 下次 poll 會重新讀到 $to 之後的全部訊息。
     */
    public function seek(string $group, string $topic, int $to = 0): void
    {
        $topic = $this->safeName($topic);
        $n = $this->partitionCount($topic);
        $offsets = $this->readOffsets();
        for ($p = 0; $p < $n; $p++) {
            $offsets[$this->offsetKey($group, $topic, $p)] = max(0, $to);
        }
        $this->writeOffsets($offsets);
    }

    /**
     * 某 group 的消費進度概觀（含 lag = logEndOffset - committed）。
     * @return list<array{partition:int,committed:int,logEnd:int,lag:int}>
     */
    public function groupStatus(string $group, string $topic): array
    {
        $topic = $this->safeName($topic);
        $n = $this->partitionCount($topic);
        $out = [];
        for ($p = 0; $p < $n; $p++) {
            $committed = $this->committedOffset($group, $topic, $p);
            $end = $this->logEndOffset($topic, $p);
            $out[] = [
                'partition' => $p,
                'committed' => $committed,
                'logEnd' => $end,
                'lag' => max(0, $end - $committed),
            ];
        }
        return $out;
    }

    // ============ rebalance：分區指派 ============

    /**
     * 把 topic 的所有分區指派給組內 consumer。
     * @param list<string> $consumers consumer id 清單
     * @param string $strategy "range" | "roundrobin"
     * @return array<string,list<int>> consumerId => 被指派的分區清單
     */
    public function assign(string $topic, array $consumers, string $strategy = 'range'): array
    {
        $topic = $this->safeName($topic);
        $n = $this->partitionCount($topic);
        $consumers = array_values(array_filter($consumers, static fn($c) => $c !== ''));
        sort($consumers); // 穩定指派
        $c = count($consumers);

        $result = [];
        foreach ($consumers as $cid) {
            $result[$cid] = [];
        }
        if ($c === 0 || $n === 0) {
            return $result;
        }

        if ($strategy === 'roundrobin') {
            // round-robin：分區輪流發給 consumer
            for ($p = 0; $p < $n; $p++) {
                $result[$consumers[$p % $c]][] = $p;
            }
        } else {
            // range：把連續分區切段分給 consumer（同一 topic 內相鄰分區歸同一 consumer）
            $per = intdiv($n, $c);
            $extra = $n % $c;
            $cursor = 0;
            for ($i = 0; $i < $c; $i++) {
                $take = $per + ($i < $extra ? 1 : 0); // 前 extra 個 consumer 多拿一個
                for ($k = 0; $k < $take; $k++) {
                    $result[$consumers[$i]][] = $cursor++;
                }
            }
        }
        return $result;
    }

    // ============ 輔助 ============

    /** 計算開啟中檔案的行數（不改動檔案指標的呼叫端責任） */
    private function countLines($fp): int
    {
        $pos = ftell($fp);
        rewind($fp);
        $count = 0;
        while (($line = fgets($fp)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }
        if ($pos !== false) {
            fseek($fp, $pos);
        }
        return $count;
    }

    /** 主題/組名只允許安全字元，避免路徑穿越 */
    private function safeName(string $name): string
    {
        $name = preg_replace('/[^0-9A-Za-z_\-]/', '', $name) ?? '';
        return $name === '' ? 'default' : $name;
    }
}
