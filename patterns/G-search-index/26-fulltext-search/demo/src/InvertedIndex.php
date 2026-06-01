<?php
declare(strict_types=1);

/**
 * 倒排索引 + BM25 全文搜尋引擎（教學版）
 * --------------------------------------------------
 * 真實系統（Elasticsearch / Lucene）：索引分片(shard)、副本、位置索引、
 * 近即時 refresh、查詢 scatter-gather。本檔聚焦「面試真正的考點」：
 *   1. tokenize（分析器）：小寫化 + 切詞 + 可選 stopwords。
 *   2. 倒排索引：term → postings(docId → tf)；另存每篇 doc 長度、avgdl、N。
 *   3. BM25 排序：k1=1.2, b=0.75，idf=log((N-df+0.5)/(df+0.5)+1)。
 *   4. 布林查詢：AND=交集、OR=聯集，皆以 BM25 排序。
 * 索引持久化到 data/（JSON）。
 *
 * 嚴禁 mb_、gmp_、openssl_、sqlite；切詞以 ASCII strtolower + preg_split。
 */
final class InvertedIndex
{
    private const K1 = 1.2;
    private const B  = 0.75;

    /** 常見英文 stopwords 簡表（教學用，可關閉） */
    private const STOPWORDS = [
        'a' => true, 'an' => true, 'the' => true, 'is' => true, 'are' => true,
        'was' => true, 'were' => true, 'be' => true, 'to' => true, 'of' => true,
        'in' => true, 'on' => true, 'at' => true, 'and' => true, 'or' => true,
        'for' => true, 'with' => true, 'as' => true, 'by' => true, 'it' => true,
        'this' => true, 'that' => true,
    ];

    private string $indexFile;
    private string $docsFile;
    private string $counterFile;

    /** @var array<string,array<int,int>> term → (docId → tf) 倒排索引 */
    private array $postings = [];
    /** @var array<int,int> docId → doc 總詞數（長度） */
    private array $docLen = [];
    /** @var array<int,array{id:int,title:string,body:string}> 文件儲存 */
    private array $docs = [];

    public function __construct(string $dataDir, private bool $useStopwords = true)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->indexFile   = $dataDir . '/index.json';
        $this->docsFile    = $dataDir . '/docs.json';
        $this->counterFile = $dataDir . '/counter.txt';
        $this->load();
    }

    // ============ 分析器（分詞） ============

    /**
     * tokenize：小寫化 + 以非英數字切詞 + 可選去 stopwords。
     * 純 ASCII（不用 mb_*）；中文可改 preg_split('//u') 單字切分，這裡以英文 demo 為主。
     * @return string[]
     */
    public function tokenize(string $text): array
    {
        $lower = strtolower($text); // ASCII 小寫
        $parts = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$this->useStopwords) {
            return $parts;
        }
        $out = [];
        foreach ($parts as $t) {
            if (!isset(self::STOPWORDS[$t])) {
                $out[] = $t;
            }
        }
        return $out;
    }

    // ============ 建索引 ============

    /** 新增一篇文件，回傳 docId */
    public function addDocument(string $title, string $body): int
    {
        $id = $this->nextId();
        $this->docs[$id] = ['id' => $id, 'title' => $title, 'body' => $body];

        // 標題與內文都進索引（標題權重可加，這裡簡化為同權）
        $tokens = $this->tokenize($title . ' ' . $body);
        $this->docLen[$id] = count($tokens);

        $tf = [];
        foreach ($tokens as $t) {
            $tf[$t] = ($tf[$t] ?? 0) + 1;
        }
        foreach ($tf as $term => $freq) {
            $this->postings[$term][$id] = $freq;
        }

        $this->save();
        return $id;
    }

    // ============ 查詢 ============

    /**
     * 搜尋：op = 'and'（含全部 query term）或 'or'（含任一）。
     * 兩者皆以 BM25 評分排序。
     * @return array<int,array{id:int,title:string,body:string,score:float,matched:string[]}>
     */
    public function search(string $query, string $op = 'or'): array
    {
        $qTerms = array_values(array_unique($this->tokenize($query)));
        if ($qTerms === []) {
            return [];
        }

        // 各 query term 命中的 docId 集合
        $termDocs = [];
        foreach ($qTerms as $term) {
            $termDocs[$term] = isset($this->postings[$term])
                ? array_keys($this->postings[$term])
                : [];
        }

        // 候選 docId：AND=交集、OR=聯集
        $candidates = $this->candidateDocs($qTerms, $termDocs, $op);
        if ($candidates === []) {
            return [];
        }

        $avgdl = $this->avgdl();
        $N = max(1, count($this->docLen));

        $results = [];
        foreach ($candidates as $docId) {
            $score = 0.0;
            $matched = [];
            foreach ($qTerms as $term) {
                $tf = $this->postings[$term][$docId] ?? 0;
                if ($tf <= 0) {
                    continue;
                }
                $matched[] = $term;
                $df = count($this->postings[$term]);
                $score += $this->bm25TermScore($tf, $df, $N, $this->docLen[$docId], $avgdl);
            }
            $results[] = [
                'id'      => $docId,
                'title'   => $this->docs[$docId]['title'] ?? '',
                'body'    => $this->docs[$docId]['body'] ?? '',
                'score'   => round($score, 4),
                'matched' => $matched,
            ];
        }

        usort($results, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return $results;
    }

    /**
     * 單一 term 的 BM25 貢獻分數。
     *   idf = log((N - df + 0.5) / (df + 0.5) + 1)
     *   score = idf * (tf*(k1+1)) / (tf + k1*(1 - b + b*dl/avgdl))
     */
    private function bm25TermScore(int $tf, int $df, int $N, int $dl, float $avgdl): float
    {
        $idf = log(($N - $df + 0.5) / ($df + 0.5) + 1.0);
        $denom = $tf + self::K1 * (1 - self::B + self::B * ($dl / max($avgdl, 1e-9)));
        return $idf * (($tf * (self::K1 + 1)) / max($denom, 1e-9));
    }

    /**
     * 候選文件集合。
     * @param string[] $qTerms
     * @param array<string,int[]> $termDocs
     * @return int[]
     */
    private function candidateDocs(array $qTerms, array $termDocs, string $op): array
    {
        if (strtolower($op) === 'and') {
            // 交集：必須含全部「有出現在索引中」的 query term
            $sets = [];
            foreach ($qTerms as $term) {
                $sets[] = $termDocs[$term]; // 某詞不存在 → 空集合 → 交集為空
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

        // OR：聯集
        $union = [];
        foreach ($termDocs as $docs) {
            foreach ($docs as $d) {
                $union[$d] = true;
            }
        }
        return array_keys($union);
    }

    // ============ 統計 ============

    public function docCount(): int
    {
        return count($this->docLen);
    }

    public function termCount(): int
    {
        return count($this->postings);
    }

    public function avgdl(): float
    {
        if ($this->docLen === []) {
            return 0.0;
        }
        return array_sum($this->docLen) / count($this->docLen);
    }

    /** @return array<int,array{id:int,title:string,body:string}> */
    public function allDocs(): array
    {
        return $this->docs;
    }

    /** 回傳某 term 的 postings（除錯 / 教學用） @return array<int,int> */
    public function postingsOf(string $term): array
    {
        return $this->postings[strtolower($term)] ?? [];
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
        if (file_exists($this->indexFile)) {
            $idx = json_decode((string) file_get_contents($this->indexFile), true);
            if (is_array($idx)) {
                $this->postings = $idx['postings'] ?? [];
                // JSON 物件鍵為字串 → 還原成 int docId
                foreach ($this->postings as $term => $pl) {
                    $fixed = [];
                    foreach ($pl as $docId => $tf) {
                        $fixed[(int) $docId] = (int) $tf;
                    }
                    $this->postings[$term] = $fixed;
                }
                $this->docLen = [];
                foreach (($idx['docLen'] ?? []) as $docId => $len) {
                    $this->docLen[(int) $docId] = (int) $len;
                }
            }
        }
        if (file_exists($this->docsFile)) {
            $docs = json_decode((string) file_get_contents($this->docsFile), true);
            if (is_array($docs)) {
                foreach ($docs as $docId => $d) {
                    $this->docs[(int) $docId] = [
                        'id'    => (int) ($d['id'] ?? $docId),
                        'title' => (string) ($d['title'] ?? ''),
                        'body'  => (string) ($d['body'] ?? ''),
                    ];
                }
            }
        }
        if (!file_exists($this->counterFile)) {
            file_put_contents($this->counterFile, '0');
        }
    }

    private function save(): void
    {
        $idx = ['postings' => $this->postings, 'docLen' => $this->docLen];
        file_put_contents(
            $this->indexFile,
            json_encode($idx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        file_put_contents(
            $this->docsFile,
            json_encode($this->docs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
