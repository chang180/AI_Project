<?php
declare(strict_types=1);

require_once __DIR__ . '/PageRank.php';

/**
 * 分片倒排索引 + scatter-gather 查詢 + BM25×PageRank 融合排序（教學版）
 * --------------------------------------------------------------------
 * 這是第 ㉖ 題「全文搜尋」的「全鏈強化版」：
 *   ㉖ = 單一倒排索引 + BM25。
 *   本題 = 倒排索引「分片」+ 跨片 scatter-gather + 連結圖 PageRank + 融合排序。
 *
 * 真實系統（Google / Elasticsearch）裡，索引大到單機放不下：
 *   1. 分片(shard)：文件依 docId 雜湊分散到 N 個 shard，每個 shard 只持有
 *      自己那批文件的倒排索引（term → postings）與長度統計。
 *   2. scatter-gather 查詢：協調者把查詢「scatter」（散開）送到所有 shard，
 *      各 shard 在自己的局部索引上算 BM25 取出局部 top 候選，再「gather」
 *      （收集）回協調者合併。單機在此用迴圈模擬「平行送到各 shard」。
 *   3. 融合排序：最終分數 = α·BM25(相關性) + β·PageRank(權威)。
 *      BM25 回答「這頁跟查詢多相關」，PageRank 回答「這頁多有份量」，
 *      兩者正規化到 [0,1] 後加權相加，可調 α/β 看排序如何變化。
 *   4. 查詢快取：query(正規化後)+α+β → 結果，命中即回，省掉重算。
 *
 * 環境限制：PHP 8.4 零依賴，僅 json + hash；切詞用 ASCII strtolower + preg_split；
 * 共享狀態以 flock 寫 data/（JSON）。
 */
final class ShardedIndex
{
    private const K1 = 1.2;
    private const B  = 0.75;

    /** 常見英文 stopwords 簡表（教學用） */
    private const STOPWORDS = [
        'a' => true, 'an' => true, 'the' => true, 'is' => true, 'are' => true,
        'was' => true, 'were' => true, 'be' => true, 'to' => true, 'of' => true,
        'in' => true, 'on' => true, 'at' => true, 'and' => true, 'or' => true,
        'for' => true, 'with' => true, 'as' => true, 'by' => true, 'it' => true,
        'this' => true, 'that' => true,
    ];

    private string $dataDir;
    private string $docsFile;
    private string $shardsFile;
    private string $prFile;
    private string $cacheFile;
    private string $counterFile;

    private int $shardCount;

    /** @var array<int,array{id:int,title:string,body:string,links:int[]}> 文件儲存 */
    private array $docs = [];

    /**
     * 分片倒排索引。
     * shards[s]['postings'][term][docId] = tf
     * shards[s]['docLen'][docId] = 詞數
     * @var array<int,array{postings:array<string,array<int,int>>,docLen:array<int,int>}>
     */
    private array $shards = [];

    /** @var array<int,float> docId → PageRank 分數 */
    private array $pagerank = [];
    /** @var array<string,mixed> PageRank 計算的中繼資料 */
    private array $prMeta = [];

    private int $cacheHit = 0;
    private int $cacheMiss = 0;

    public function __construct(string $dataDir, int $shardCount = 4)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dataDir     = $dataDir;
        $this->shardCount  = max(1, $shardCount);
        $this->docsFile    = $dataDir . '/docs.json';
        $this->shardsFile  = $dataDir . '/shards.json';
        $this->prFile      = $dataDir . '/pagerank.json';
        $this->cacheFile   = $dataDir . '/query_cache.json';
        $this->counterFile = $dataDir . '/counter.txt';
        $this->load();
    }

    // ============ 分析器（分詞） ============

    /**
     * tokenize：ASCII 小寫 + 以非英數字切詞 + 去 stopwords。
     * @return string[]
     */
    public function tokenize(string $text): array
    {
        $lower = strtolower($text);
        $parts = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $t) {
            if (!isset(self::STOPWORDS[$t])) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** 文件依 docId 雜湊分到哪個 shard（crc32 確保均勻分散） */
    public function shardOf(int $docId): int
    {
        return crc32((string) $docId) % $this->shardCount;
    }

    // ============ 建索引 ============

    /**
     * 新增一篇文件（含外連 links），回傳 docId。
     * links 是這頁連到的其他 docId（建立連結圖供 PageRank 用）。
     * @param int[] $links
     */
    public function addDocument(string $title, string $body, array $links = []): int
    {
        $id = $this->nextId();
        $links = array_values(array_unique(array_map('intval', $links)));
        $this->docs[$id] = ['id' => $id, 'title' => $title, 'body' => $body, 'links' => $links];

        $s = $this->shardOf($id);
        if (!isset($this->shards[$s])) {
            $this->shards[$s] = ['postings' => [], 'docLen' => []];
        }

        $tokens = $this->tokenize($title . ' ' . $body);
        $this->shards[$s]['docLen'][$id] = count($tokens);

        $tf = [];
        foreach ($tokens as $t) {
            $tf[$t] = ($tf[$t] ?? 0) + 1;
        }
        foreach ($tf as $term => $freq) {
            $this->shards[$s]['postings'][$term][$id] = $freq;
        }

        // 文件異動 → 既有 PageRank 與查詢快取失效（近即時索引：需重算/重整）
        $this->invalidateDerived();
        $this->save();
        return $id;
    }

    // ============ PageRank ============

    /**
     * 在所有文件的連結圖上計算 PageRank，存回 data/。
     * @return array{scores:array<int,float>,iterations:int,converged:bool,
     *               damping:float,danglingCount:int}
     */
    public function computePageRank(float $damping = 0.85): array
    {
        $graph = [];
        foreach ($this->docs as $id => $doc) {
            // 只保留指向「存在的文件」的連結
            $graph[$id] = array_values(array_filter(
                $doc['links'],
                fn(int $dst): bool => isset($this->docs[$dst])
            ));
        }
        $pr = (new PageRank($damping))->compute($graph);
        $this->pagerank = $pr['scores'];
        $this->prMeta = [
            'iterations'    => $pr['iterations'],
            'converged'     => $pr['converged'],
            'damping'       => $pr['damping'],
            'danglingCount' => $pr['danglingCount'],
        ];
        $this->saveDerived();
        return $pr;
    }

    // ============ 查詢：scatter-gather + 融合排序 ============

    /**
     * 搜尋。
     *   1. 解析查詢（AND/OR）。
     *   2. scatter：送到每個 shard，各自算局部 BM25 候選。
     *   3. gather：協調者合併所有 shard 的候選。
     *   4. 融合排序：score = α·normBM25 + β·normPageRank。
     *   5. 查詢快取：相同 (query,α,β) 命中即回。
     *
     * @return array{
     *   query:string, op:string, alpha:float, beta:float, cached:bool,
     *   shardCount:int, perShardCandidates:array<int,int>,
     *   results:array<int,array<string,mixed>>
     * }
     */
    public function search(string $query, string $op = 'or', float $alpha = 0.7, float $beta = 0.3): array
    {
        $qTerms = array_values(array_unique($this->tokenize($query)));
        $op = strtolower($op) === 'and' ? 'and' : 'or';

        $cacheKey = $this->cacheKey($qTerms, $op, $alpha, $beta);

        if ($qTerms === []) {
            return [
                'query' => $query, 'op' => $op, 'alpha' => $alpha, 'beta' => $beta,
                'cached' => false, 'shardCount' => $this->shardCount,
                'perShardCandidates' => [], 'results' => [],
            ];
        }

        // ---- 查詢快取：命中即回 ----
        $cache = $this->readCache();
        if (isset($cache[$cacheKey])) {
            $this->cacheHit++;
            $hit = $cache[$cacheKey];
            $hit['cached'] = true;
            return $hit;
        }
        $this->cacheMiss++;

        // 全域統計：BM25 的 idf 與 avgdl 需跨 shard 的「全集合」視角。
        // 真實系統會分散維護全域 df/avgdl（或近似），這裡集中算以保證分數正確。
        [$globalDf, $avgdl, $N] = $this->globalStats($qTerms);

        // ---- scatter：對每個 shard 各自算局部 BM25 候選 ----
        $perShardCandidates = [];
        $gathered = []; // docId → bm25 raw score
        for ($s = 0; $s < $this->shardCount; $s++) {
            $local = $this->searchShard($s, $qTerms, $op, $globalDf, $avgdl, $N);
            $perShardCandidates[$s] = count($local);
            foreach ($local as $docId => $score) {
                $gathered[$docId] = $score; // 各 doc 只屬於一個 shard，直接收集
            }
        }

        if ($gathered === []) {
            $empty = [
                'query' => $query, 'op' => $op, 'alpha' => $alpha, 'beta' => $beta,
                'cached' => false, 'shardCount' => $this->shardCount,
                'perShardCandidates' => $perShardCandidates, 'results' => [],
            ];
            $this->writeCache($cacheKey, $empty);
            return $empty;
        }

        // ---- gather + 融合排序 ----
        $maxBm25 = max($gathered) ?: 1.0;
        $maxPr = $this->pagerank !== [] ? max($this->pagerank) : 0.0;

        $results = [];
        foreach ($gathered as $docId => $bm25) {
            $normBm25 = $maxBm25 > 0 ? $bm25 / $maxBm25 : 0.0;
            $prRaw = $this->pagerank[$docId] ?? 0.0;
            $normPr = $maxPr > 0 ? $prRaw / $maxPr : 0.0;
            $final = $alpha * $normBm25 + $beta * $normPr;
            $results[] = [
                'id'        => $docId,
                'title'     => $this->docs[$docId]['title'] ?? '',
                'shard'     => $this->shardOf($docId),
                'bm25'      => round($bm25, 4),
                'normBm25'  => round($normBm25, 4),
                'pagerank'  => round($prRaw, 6),
                'normPr'    => round($normPr, 4),
                'score'     => round($final, 4),
            ];
        }

        // 最終排序：融合分數由高到低
        usort($results, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        $out = [
            'query' => $query, 'op' => $op, 'alpha' => $alpha, 'beta' => $beta,
            'cached' => false, 'shardCount' => $this->shardCount,
            'perShardCandidates' => $perShardCandidates, 'results' => $results,
        ];
        $this->writeCache($cacheKey, $out);
        return $out;
    }

    /**
     * 單一 shard 的局部查詢：在這個 shard 的局部索引上算 BM25。
     * 回傳 docId → bm25 分數。
     * @param string[] $qTerms
     * @param array<string,int> $globalDf
     * @return array<int,float>
     */
    private function searchShard(int $s, array $qTerms, string $op, array $globalDf, float $avgdl, int $N): array
    {
        if (!isset($this->shards[$s])) {
            return [];
        }
        $postings = $this->shards[$s]['postings'];
        $docLen   = $this->shards[$s]['docLen'];

        // 此 shard 中各 term 命中的 docId
        $termDocs = [];
        foreach ($qTerms as $term) {
            $termDocs[$term] = isset($postings[$term]) ? array_keys($postings[$term]) : [];
        }

        $candidates = $this->candidateDocs($qTerms, $termDocs, $op);
        if ($candidates === []) {
            return [];
        }

        $scores = [];
        foreach ($candidates as $docId) {
            $score = 0.0;
            foreach ($qTerms as $term) {
                $tf = $postings[$term][$docId] ?? 0;
                if ($tf <= 0) {
                    continue;
                }
                $df = $globalDf[$term] ?? 1; // 用全域 df 保證跨 shard 一致
                $score += $this->bm25TermScore($tf, $df, $N, $docLen[$docId] ?? 1, $avgdl);
            }
            $scores[$docId] = $score;
        }
        return $scores;
    }

    /**
     * 候選文件：AND=交集、OR=聯集（限本 shard）。
     * @param string[] $qTerms
     * @param array<string,int[]> $termDocs
     * @return int[]
     */
    private function candidateDocs(array $qTerms, array $termDocs, string $op): array
    {
        if ($op === 'and') {
            $sets = [];
            foreach ($qTerms as $term) {
                $sets[] = $termDocs[$term];
            }
            if ($sets === []) {
                return [];
            }
            $acc = $sets[0];
            for ($i = 1, $n = count($sets); $i < $n; $i++) {
                $acc = array_values(array_intersect($acc, $sets[$i]));
                if ($acc === []) {
                    break;
                }
            }
            return $acc;
        }
        $union = [];
        foreach ($termDocs as $docs) {
            foreach ($docs as $d) {
                $union[$d] = true;
            }
        }
        return array_keys($union);
    }

    /**
     * 單一 term 的 BM25 貢獻。
     *   idf = log((N - df + 0.5)/(df + 0.5) + 1)
     *   score = idf * (tf*(k1+1)) / (tf + k1*(1 - b + b*dl/avgdl))
     */
    private function bm25TermScore(int $tf, int $df, int $N, int $dl, float $avgdl): float
    {
        $idf = log(($N - $df + 0.5) / ($df + 0.5) + 1.0);
        $denom = $tf + self::K1 * (1 - self::B + self::B * ($dl / max($avgdl, 1e-9)));
        return $idf * (($tf * (self::K1 + 1)) / max($denom, 1e-9));
    }

    /**
     * 全域統計：跨所有 shard 的 df（每個 query term）、平均文件長度、總文件數。
     * @param string[] $qTerms
     * @return array{0:array<string,int>,1:float,2:int}
     */
    private function globalStats(array $qTerms): array
    {
        $df = [];
        foreach ($qTerms as $term) {
            $df[$term] = 0;
        }
        $totalLen = 0;
        $docCount = 0;
        foreach ($this->shards as $shard) {
            foreach ($qTerms as $term) {
                if (isset($shard['postings'][$term])) {
                    $df[$term] += count($shard['postings'][$term]);
                }
            }
            foreach ($shard['docLen'] as $len) {
                $totalLen += $len;
                $docCount++;
            }
        }
        $avgdl = $docCount > 0 ? $totalLen / $docCount : 0.0;
        return [$df, $avgdl, max(1, $docCount)];
    }

    // ============ 查詢快取 ============

    /** @param string[] $qTerms */
    private function cacheKey(array $qTerms, string $op, float $alpha, float $beta): string
    {
        sort($qTerms);
        return hash('sha256', implode(' ', $qTerms) . "|$op|$alpha|$beta");
    }

    /** @return array<string,array<string,mixed>> */
    private function readCache(): array
    {
        if (!file_exists($this->cacheFile)) {
            return [];
        }
        $c = json_decode((string) file_get_contents($this->cacheFile), true);
        return is_array($c) ? $c : [];
    }

    private function writeCache(string $key, array $value): void
    {
        $cache = $this->readCache();
        // 簡易容量上限：超過 200 筆就清空（教學版，真實系統用 LRU/TTL）
        if (count($cache) > 200) {
            $cache = [];
        }
        $cache[$key] = $value;
        file_put_contents(
            $this->cacheFile,
            json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    public function clearCache(): void
    {
        if (file_exists($this->cacheFile)) {
            file_put_contents($this->cacheFile, '{}', LOCK_EX);
        }
    }

    private function invalidateDerived(): void
    {
        $this->clearCache();
        // PageRank 不自動清（保留上次結果供對照），但提示需重算
    }

    // ============ 狀態 / 統計 ============

    public function shardCount(): int
    {
        return $this->shardCount;
    }

    public function docCount(): int
    {
        return count($this->docs);
    }

    /** 每個 shard 的文件數、term 數（展示分片分布） @return array<int,array{docs:int,terms:int}> */
    public function shardStats(): array
    {
        $out = [];
        for ($s = 0; $s < $this->shardCount; $s++) {
            $out[$s] = [
                'docs'  => isset($this->shards[$s]) ? count($this->shards[$s]['docLen']) : 0,
                'terms' => isset($this->shards[$s]) ? count($this->shards[$s]['postings']) : 0,
            ];
        }
        return $out;
    }

    /** @return array<int,array{id:int,title:string,body:string,links:int[]}> */
    public function allDocs(): array
    {
        return $this->docs;
    }

    /** @return array<int,float> */
    public function pagerankScores(): array
    {
        return $this->pagerank;
    }

    /** @return array<string,mixed> */
    public function pagerankMeta(): array
    {
        return $this->prMeta;
    }

    public function cacheStats(): array
    {
        return ['hit' => $this->cacheHit, 'miss' => $this->cacheMiss];
    }

    // ============ 持久化 ============

    private function nextId(): int
    {
        $fp = fopen($this->counterFile, 'c+');
        flock($fp, LOCK_EX);
        $id = (int) trim((string) fread($fp, 64));
        $id++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $id);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $id;
    }

    private function load(): void
    {
        if (file_exists($this->docsFile)) {
            $docs = json_decode((string) file_get_contents($this->docsFile), true);
            if (is_array($docs)) {
                foreach ($docs as $id => $d) {
                    $this->docs[(int) $id] = [
                        'id'    => (int) ($d['id'] ?? $id),
                        'title' => (string) ($d['title'] ?? ''),
                        'body'  => (string) ($d['body'] ?? ''),
                        'links' => array_map('intval', $d['links'] ?? []),
                    ];
                }
            }
        }
        if (file_exists($this->shardsFile)) {
            $sh = json_decode((string) file_get_contents($this->shardsFile), true);
            if (is_array($sh)) {
                foreach ($sh as $s => $shard) {
                    $postings = [];
                    foreach (($shard['postings'] ?? []) as $term => $pl) {
                        $fixed = [];
                        foreach ($pl as $docId => $tf) {
                            $fixed[(int) $docId] = (int) $tf;
                        }
                        $postings[(string) $term] = $fixed;
                    }
                    $docLen = [];
                    foreach (($shard['docLen'] ?? []) as $docId => $len) {
                        $docLen[(int) $docId] = (int) $len;
                    }
                    $this->shards[(int) $s] = ['postings' => $postings, 'docLen' => $docLen];
                }
            }
        }
        if (file_exists($this->prFile)) {
            $pr = json_decode((string) file_get_contents($this->prFile), true);
            if (is_array($pr)) {
                foreach (($pr['scores'] ?? []) as $docId => $score) {
                    $this->pagerank[(int) $docId] = (float) $score;
                }
                $this->prMeta = is_array($pr['meta'] ?? null) ? $pr['meta'] : [];
            }
        }
        if (!file_exists($this->counterFile)) {
            file_put_contents($this->counterFile, '100'); // 起始 docId
        }
    }

    private function save(): void
    {
        file_put_contents(
            $this->docsFile,
            json_encode($this->docs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
        file_put_contents(
            $this->shardsFile,
            json_encode($this->shards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function saveDerived(): void
    {
        file_put_contents(
            $this->prFile,
            json_encode(
                ['scores' => $this->pagerank, 'meta' => $this->prMeta],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            LOCK_EX
        );
    }
}
