<?php
declare(strict_types=1);

/**
 * Trie 字典樹（對應真實系統「駐記憶體的 Trie 索引」）
 * --------------------------------------------------------------
 * - insert(詞, 權重)：把一個查詢詞與它的頻率插入 Trie。
 * - allWithPrefix(前綴)：給定前綴，蒐集所有以此前綴開頭的完成詞。
 *
 * ★ 中文 / 多語言：本機 PHP 8.4 未載入 mbstring，故一律用
 *   PCRE preg_split('//u', ...) 以「字元（code point）」為單位切割，
 *   而非位元組——否則一個中文字會被拆成 3 個位元組，前綴比對會錯。
 *   真實系統同樣需明確決定前綴的粒度（字元 vs 位元組 vs 詞）。
 */
final class TrieNode
{
    /** @var array<string,TrieNode> 子節點：字元 → 節點 */
    public array $children = [];
    /** 此節點是否為一個完整查詢詞的結尾 */
    public bool $isWord = false;
    /** 該詞的頻率（僅在 isWord 為 true 時有意義） */
    public int $freq = 0;
    /** 完整的詞（方便回傳，避免回溯重組） */
    public string $term = '';
    /**
     * ★ 預存 Top-K：以此節點為前綴的前 K 名建議。
     * 真實系統會在建索引時自底向上算好存在節點上，
     * 查詢時 O(前綴長度) 直接讀出，與候選詞數量無關。
     * @var array<int,array{term:string,freq:int}>
     */
    public array $topK = [];
}

final class Trie
{
    private TrieNode $root;

    public function __construct()
    {
        $this->root = new TrieNode();
    }

    /** 以字元（非位元組）切割字串，支援中文。 */
    public static function chars(string $s): array
    {
        $parts = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? [] : $parts;
    }

    /**
     * 插入一個查詢詞與其頻率。
     * 若詞已存在，覆寫為較大的頻率（重覆載入時取最新）。
     */
    public function insert(string $term, int $freq): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $node = $this->root;
        foreach (self::chars($term) as $ch) {
            if (!isset($node->children[$ch])) {
                $node->children[$ch] = new TrieNode();
            }
            $node = $node->children[$ch];
        }
        $node->isWord = true;
        $node->term = $term;
        $node->freq = $freq;
    }

    /** 走到前綴對應的節點；走不到回 null（沒有任何詞以此前綴開頭）。 */
    public function nodeForPrefix(string $prefix): ?TrieNode
    {
        $node = $this->root;
        foreach (self::chars($prefix) as $ch) {
            if (!isset($node->children[$ch])) {
                return null;
            }
            $node = $node->children[$ch];
        }
        return $node;
    }

    /**
     * 基本款：給定前綴，DFS 蒐集子樹下所有完成詞。
     * 量大時很慢（最壞要走遍整棵子樹）——這正是為何要在節點預存 Top-K。
     * @return array<int,array{term:string,freq:int}>
     */
    public function allWithPrefix(string $prefix): array
    {
        $start = $this->nodeForPrefix($prefix);
        if ($start === null) {
            return [];
        }
        $out = [];
        $this->collect($start, $out);
        return $out;
    }

    /** @param array<int,array{term:string,freq:int}> $out */
    private function collect(TrieNode $node, array &$out): void
    {
        if ($node->isWord) {
            $out[] = ['term' => $node->term, 'freq' => $node->freq];
        }
        foreach ($node->children as $child) {
            $this->collect($child, $out);
        }
    }

    public function root(): TrieNode
    {
        return $this->root;
    }
}
