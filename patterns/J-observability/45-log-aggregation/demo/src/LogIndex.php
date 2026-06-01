<?php
declare(strict_types=1);

/**
 * 日誌聚合（Log Aggregation, ELK 風格）— 倒排索引 + 攝取管線的單機教學版
 * --------------------------------------------------------------------------
 * 真實 ELK：Filebeat 收集 → Kafka 緩衝 → Logstash 處理 → Elasticsearch 建倒排索引
 * → Kibana 查詢。Elasticsearch 對每筆結構化日誌文件做 tokenize，建立「詞 → 文件清單」
 * 的倒排索引（inverted index），查詢時取詞的 posting list 取交集，再套等級 / 時間範圍過濾。
 *
 * 這裡用 JSON 檔當持久層、flock 保證多請求安全，把以下「核心邏輯」做成真實可跑：
 *   - 攝取（ingest）：把一筆 log（時間 / 等級 / 服務 / 訊息）存成結構化文件，分配遞增 id
 *   - tokenize：把訊息切成小寫詞（呼應第㉖題全文搜尋）
 *   - 倒排索引：詞 → 哪幾筆 log（posting list）
 *   - 查詢：關鍵字（多詞取交集）+ 等級過濾 + 時間範圍 [from, to]，比逐筆掃描快
 *
 * 與第㉖題全文搜尋的差異：本題多了「攝取管線」「等級維度」「時間範圍」三個日誌特有面向。
 *
 * 名詞：
 *   - 文件（document）：一筆結構化 log = { id, ts(毫秒), level, service, message }
 *   - token（詞）：訊息 tokenize 後的最小檢索單位（小寫、去標點）
 *   - posting list：某個詞出現在「哪幾筆 log id」的清單（倒排索引的值）
 */
final class LogIndex
{
    private string $dataDir;
    private string $dbFile; // 文件 + 倒排索引 + 計數器

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dataDir = $dataDir;
        $this->dbFile = $dataDir . '/logs.json';
        if (!file_exists($this->dbFile)) {
            file_put_contents(
                $this->dbFile,
                json_encode(['nextId' => 1, 'docs' => [], 'inverted' => []])
            );
        }
    }

    // ============ tokenize ============

    /**
     * 把訊息切成可檢索的詞：轉小寫、用非英數字元切割、去空白。
     * 例： "GET /api/users 500 timeout" → ['get','api','users','500','timeout']
     * 注意：不可用 mb_*，這裡用 ASCII 規則處理（教學版足夠；中文整段視為一詞）。
     */
    public function tokenize(string $text): array
    {
        $lower = strtolower($text);
        // 以非 [a-z0-9_] 字元為分隔（保留底線，常見於 service 名）
        $parts = preg_split('/[^a-z0-9_]+/', $lower) ?: [];
        $tokens = [];
        foreach ($parts as $p) {
            if ($p !== '') {
                $tokens[$p] = true; // 去重：同一詞在一筆 log 只記一次
            }
        }
        return array_keys($tokens);
    }

    // ============ 攝取（ingest）============

    /**
     * 攝取一筆 log，建立結構化文件並更新倒排索引。
     * 回傳分配到的文件 id。
     *
     * @param string $level   等級：debug/info/warn/error（大小寫不敏感，存為小寫）
     * @param string $service 來源服務名（如 auth-service）
     * @param string $message 日誌訊息（會被 tokenize 進倒排索引）
     * @param int    $tsMs    事件時間戳（毫秒）；缺省由呼叫端帶入攝取當下
     */
    public function ingest(string $level, string $service, string $message, int $tsMs): int
    {
        $db = $this->read();
        $id = (int) $db['nextId'];
        $db['nextId'] = $id + 1;

        $level = strtolower(trim($level));
        if (!in_array($level, ['debug', 'info', 'warn', 'error'], true)) {
            $level = 'info';
        }

        $db['docs'][(string) $id] = [
            'id' => $id,
            'ts' => $tsMs,
            'level' => $level,
            'service' => $service,
            'message' => $message,
        ];

        // 更新倒排索引：訊息 + 服務名都 tokenize（讓服務名也可被關鍵字命中）
        $tokens = $this->tokenize($message . ' ' . $service);
        foreach ($tokens as $tok) {
            if (!isset($db['inverted'][$tok])) {
                $db['inverted'][$tok] = [];
            }
            // posting list 保持遞增、去重
            $db['inverted'][$tok][] = $id;
        }

        $this->write($db);
        return $id;
    }

    /** 批次攝取，回傳分配到的 id 陣列。 */
    public function ingestBatch(array $entries, int $defaultTsMs): array
    {
        $ids = [];
        foreach ($entries as $e) {
            if (!is_array($e) || !isset($e['message'])) {
                continue;
            }
            $ids[] = $this->ingest(
                (string) ($e['level'] ?? 'info'),
                (string) ($e['service'] ?? 'unknown'),
                (string) $e['message'],
                isset($e['ts']) ? (int) $e['ts'] : $defaultTsMs
            );
        }
        return $ids;
    }

    // ============ 查詢 ============

    /**
     * 查詢：關鍵字（多詞 AND 取交集）+ 等級過濾 + 時間範圍 [fromMs, toMs]。
     *
     * 流程（這正是倒排索引的精髓）：
     *   1) q tokenize 成多個詞 → 每個詞取 posting list → 取「交集」得候選 id
     *      （沒給 q 時候選 = 全部文件）
     *   2) 對候選 id 套用 level 與時間範圍過濾
     *   3) 依時間新到舊排序、截斷 limit
     *
     * @param string   $q       關鍵字（空字串 = 不限關鍵字）
     * @param ?string  $level   等級過濾（null = 不限）
     * @param ?int     $fromMs  起始時間（含），null = 不限
     * @param ?int     $toMs    結束時間（含），null = 不限
     * @param int      $limit   最多回傳幾筆
     */
    public function search(
        string $q,
        ?string $level,
        ?int $fromMs,
        ?int $toMs,
        int $limit = 50
    ): array {
        $db = $this->read();
        $scanned = 0;

        // ---- 1) 用倒排索引取候選 id（關鍵字 AND）----
        $q = trim($q);
        if ($q === '') {
            // 沒關鍵字：候選 = 全部文件 id
            $candidates = array_map('intval', array_keys($db['docs']));
            $usedIndex = false;
        } else {
            $usedIndex = true;
            $terms = $this->tokenize($q);
            if ($terms === []) {
                return $this->result([], 0, true, []);
            }
            // 取每個詞的 posting list
            $lists = [];
            foreach ($terms as $t) {
                $lists[] = $db['inverted'][$t] ?? []; // 詞不存在 → 空清單 → 交集必為空
            }
            $candidates = $this->intersect($lists);
        }

        // ---- 2) 套等級 + 時間範圍過濾 ----
        $level = $level !== null ? strtolower(trim($level)) : null;
        if ($level === '') {
            $level = null;
        }
        $hits = [];
        foreach ($candidates as $id) {
            $doc = $db['docs'][(string) $id] ?? null;
            if ($doc === null) {
                continue;
            }
            $scanned++;
            if ($level !== null && $doc['level'] !== $level) {
                continue;
            }
            if ($fromMs !== null && (int) $doc['ts'] < $fromMs) {
                continue;
            }
            if ($toMs !== null && (int) $doc['ts'] > $toMs) {
                continue;
            }
            $hits[] = $doc;
        }

        // ---- 3) 時間新到舊排序 + 截斷 ----
        usort($hits, static fn($a, $b) => $b['ts'] <=> $a['ts']);
        $total = count($hits);
        if (count($hits) > $limit) {
            $hits = array_slice($hits, 0, $limit);
        }

        return $this->result($hits, $total, $usedIndex, [
            'candidates' => count($candidates),
            'scanned' => $scanned,
        ]);
    }

    /**
     * 多個 posting list 取交集（多關鍵字 AND）。
     * posting list 已是遞增；用 hash 計數法求交集，O(總長度)。
     */
    private function intersect(array $lists): array
    {
        if ($lists === []) {
            return [];
        }
        $need = count($lists);
        $counter = [];
        foreach ($lists as $list) {
            // 同一 list 內去重，避免同一詞在同篇重複（理論上 ingest 已去重）
            $seen = [];
            foreach ($list as $id) {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $counter[$id] = ($counter[$id] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($counter as $id => $c) {
            if ($c === $need) {
                $out[] = (int) $id;
            }
        }
        return $out;
    }

    private function result(array $hits, int $total, bool $usedIndex, array $stats): array
    {
        return [
            'total' => $total,
            'returned' => count($hits),
            'usedIndex' => $usedIndex,
            'stats' => $stats,
            'hits' => array_values($hits),
        ];
    }

    // ============ 觀測 / 測試頁輔助 ============

    /** 總文件數。 */
    public function docCount(): int
    {
        $db = $this->read();
        return count($db['docs']);
    }

    /** 倒排索引大小（不同詞的數量）。 */
    public function termCount(): int
    {
        $db = $this->read();
        return count($db['inverted']);
    }

    /** 各等級計數（給測試頁顯示）。 */
    public function levelCounts(): array
    {
        $db = $this->read();
        $out = ['debug' => 0, 'info' => 0, 'warn' => 0, 'error' => 0];
        foreach ($db['docs'] as $d) {
            $lv = (string) ($d['level'] ?? 'info');
            $out[$lv] = ($out[$lv] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * 取倒排索引前 N 個詞（依 posting list 長度由大到小），給測試頁示意。
     * 回傳 [ ['term'=>..,'count'=>..], ... ]
     */
    public function topTerms(int $n = 12): array
    {
        $db = $this->read();
        $rows = [];
        foreach ($db['inverted'] as $term => $list) {
            $rows[] = ['term' => (string) $term, 'count' => count($list)];
        }
        usort($rows, static fn($a, $b) => $b['count'] <=> $a['count']);
        return array_slice($rows, 0, $n);
    }

    // ============ 持久層（flock）============

    private function read(): array
    {
        $fp = fopen($this->dbFile, 'c+');
        if ($fp === false) {
            return ['nextId' => 1, 'docs' => [], 'inverted' => []];
        }
        flock($fp, LOCK_SH);
        $json = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['nextId' => 1, 'docs' => [], 'inverted' => []];
        }
        $data['nextId'] = (int) ($data['nextId'] ?? 1);
        $data['docs'] = is_array($data['docs'] ?? null) ? $data['docs'] : [];
        $data['inverted'] = is_array($data['inverted'] ?? null) ? $data['inverted'] : [];
        return $data;
    }

    private function write(array $data): void
    {
        $fp = fopen($this->dbFile, 'c+');
        if ($fp === false) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
