<?php
declare(strict_types=1);

require_once __DIR__ . '/Trie.php';

/**
 * Suggester：前綴 → 依頻率排序的 Top-K 建議（對應真實「Suggest Service」）
 * --------------------------------------------------------------
 * 兩種取得 Top-K 的方式，本類別都實作了，方便對照：
 *
 *   1) suggestByScan()  ——「查詢時排序」。走到前綴節點，蒐集所有完成詞再排序。
 *      簡單，但最壞 O(子樹大小)；熱門短前綴會炸。僅供對照。
 *
 *   2) suggest()        ——「節點預存 Top-K」。建索引時自底向上把每個節點的
 *      Top-K 算好存在節點上，查詢只要 O(前綴長度) 讀出該節點的 topK。
 *      ★ 這是真實系統的做法，也是本 demo 預設走的路徑。
 *
 * 頻率更新：select() 讓某個詞頻率 +1，並重算「受影響節點」（該詞每個前綴節點）
 * 的 Top-K，使排名即時變化。真實系統不會在讀路徑同步重算，而是把事件丟查詢日誌，
 * 由離線/近即時管線重建——這裡為了 demo 直觀，直接就地重算（範圍僅該詞的前綴鏈）。
 */
final class Suggester
{
    private Trie $trie;
    private int $k;

    public function __construct(int $k = 10)
    {
        $this->trie = new Trie();
        $this->k = $k;
    }

    /**
     * 載入一批詞（含頻率）並建索引。
     * @param array<string,int> $terms  詞 => 頻率
     */
    public function load(array $terms): void
    {
        $this->trie = new Trie();
        foreach ($terms as $term => $freq) {
            $this->trie->insert((string) $term, (int) $freq);
        }
        // 建索引：自底向上算好每個節點的 Top-K（真實系統建索引階段的核心步驟）
        $this->buildTopK($this->trie->root());
    }

    /**
     * 後序走訪：先算子節點的 Top-K，再合併出自己的 Top-K。
     * 回傳「以該節點為前綴」的 Top-K，順便存進節點的 ->topK。
     * @return array<int,array{term:string,freq:int}>
     */
    private function buildTopK(TrieNode $node): array
    {
        $candidates = [];
        if ($node->isWord) {
            $candidates[] = ['term' => $node->term, 'freq' => $node->freq];
        }
        foreach ($node->children as $child) {
            foreach ($this->buildTopK($child) as $c) {
                $candidates[] = $c;
            }
        }
        $node->topK = $this->topKOf($candidates);
        return $node->topK;
    }

    /** 依頻率（高→低）排序取前 K；頻率相同以詞字典序穩定排序。 */
    private function topKOf(array $candidates): array
    {
        usort($candidates, static function (array $a, array $b): int {
            if ($a['freq'] !== $b['freq']) {
                return $b['freq'] <=> $a['freq'];
            }
            return strcmp($a['term'], $b['term']);
        });
        return array_slice($candidates, 0, $this->k);
    }

    /**
     * ★ 預設路徑：O(前綴長度) 讀出節點預存的 Top-K。
     * @return array<int,array{term:string,freq:int}>
     */
    public function suggest(string $prefix): array
    {
        $node = $this->trie->nodeForPrefix($prefix);
        return $node === null ? [] : $node->topK;
    }

    /**
     * 對照用：查詢時才蒐集 + 排序（不靠預存）。
     * 結果應與 suggest() 一致，但複雜度高很多。
     * @return array<int,array{term:string,freq:int}>
     */
    public function suggestByScan(string $prefix): array
    {
        return $this->topKOf($this->trie->allWithPrefix($prefix));
    }

    /**
     * 某建議被選中：頻率 +1，並重算該詞所有前綴節點的 Top-K，
     * 使下次查同前綴時排名反映新頻率。
     * 回傳新的頻率；詞不存在則回 null。
     */
    public function select(string $term): ?int
    {
        $term = trim($term);
        $chars = Trie::chars($term);
        // 沿路徑收集節點鏈，同時驗證是否走得到結尾
        $node = $this->trie->root();
        $chain = [$node];
        foreach ($chars as $ch) {
            if (!isset($node->children[$ch])) {
                return null;
            }
            $node = $node->children[$ch];
            $chain[] = $node;
        }
        if (!$node->isWord) {
            return null;
        }
        $node->freq++;                 // 頻率上升
        // 自底向上重算這條前綴鏈上每個節點的 Top-K（受影響範圍）
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $this->buildTopK($chain[$i]);
        }
        return $node->freq;
    }

    public function k(): int
    {
        return $this->k;
    }
}
