<?php
declare(strict_types=1);

/**
 * RAG 檢索器（對應真實系統的「向量庫檢索 + 重排序」）
 * --------------------------------------------------
 * 真實系統：文件切塊 → 嵌入模型轉向量 → 存向量庫（HNSW/IVF）→ 查詢時取餘弦相似度 top-k → cross-encoder 重排序。
 * 這裡用一個小型政策知識庫（幾條退款/改訂政策），以「詞袋 + 關鍵字相似度」做檢索與排序示意；
 * 原理相同（把查詢與文件投影到同一空間、算相似度、取前 k），只是把「嵌入向量」換成「詞頻向量」。
 *
 * 換成真模型：把 embed() 換成嵌入 API、similarity() 換成餘弦相似度、search() 後接重排序模型即可，
 * 上層 Agent / 引用顯示的介面完全不變。
 */
final class Retriever
{
    /** @var list<array{id:string,doc:string,section:string,text:string}> 知識庫切塊 */
    private array $chunks;

    public function __construct()
    {
        // 小型政策知識庫（離線建索引時寫入；此處內嵌示意）
        $this->chunks = [
            [
                'id' => 'kb-1', 'doc' => '退款政策', 'section' => '§1 可退條件',
                'text' => '若於入住日前 48 小時以上取消訂房，可申請全額退款。退款將於 7 個工作天內退回原付款方式。',
            ],
            [
                'id' => 'kb-2', 'doc' => '退款政策', 'section' => '§2 不可退時段',
                'text' => '入住日前 48 小時內取消，或標示為「不可退款（Non-refundable）」的房型，恕不退款。',
            ],
            [
                'id' => 'kb-3', 'doc' => '退款政策', 'section' => '§3 自動退款上限',
                'text' => '金額在 NT$2000 以內的退款可由系統自動核准；超過上限的退款需轉由人工客服審核。',
            ],
            [
                'id' => 'kb-4', 'doc' => '改訂政策', 'section' => '§1 變更日期',
                'text' => '可免費變更入住日期一次，須於入住日前 72 小時提出，並視房況而定；差額需補足。',
            ],
            [
                'id' => 'kb-5', 'doc' => '付款政策', 'section' => '§1 付款方式',
                'text' => '支援信用卡與電子錢包付款。退款一律退回原付款方式，無法跨方式退款。',
            ],
        ];
    }

    /**
     * 檢索 top-k 相關片段（含相似度分數，供引用與「低分拒答」判斷）。
     * @return list<array{id:string,doc:string,section:string,text:string,score:float}>
     */
    public function search(string $query, int $k = 2): array
    {
        $qTerms = $this->tokenize($query);
        if ($qTerms === []) {
            return [];
        }

        $scored = [];
        foreach ($this->chunks as $chunk) {
            $dTerms = $this->tokenize($chunk['doc'] . ' ' . $chunk['section'] . ' ' . $chunk['text']);
            $score = $this->similarity($qTerms, $dTerms);
            if ($score > 0.0) {
                $scored[] = $chunk + ['score' => round($score, 3)];
            }
        }

        // 依相似度排序（示意「重排序」），取 top-k
        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $k);
    }

    /**
     * 詞袋/關鍵字相似度（示意嵌入相似度）：以查詢命中詞數佔比衡量，並對中文關鍵字加權。
     * @param list<string> $q
     * @param list<string> $d
     */
    private function similarity(array $q, array $d): float
    {
        $dSet = array_count_values($d);
        $hit = 0.0;
        foreach ($q as $term) {
            if (isset($dSet[$term])) {
                $hit += 1.0;
            }
        }
        // 正規化為 0~1：命中查詢詞的比例
        return $hit / count($q);
    }

    /**
     * 斷詞：英數以空白切；中文以常見政策關鍵字字典做關鍵字比對（避免逐字過度切碎）。
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        // 註：環境未必載入 mbstring；strtolower 只影響 ASCII（英數），中文位元組不受影響，
        // 而 UTF-8 為自同步編碼，下方以 strpos 做中文關鍵字子字串比對即可正確命中。
        $text = strtolower($text);
        $tokens = [];

        // 英數詞
        if (preg_match_all('/[a-z0-9]+/u', $text, $m)) {
            foreach ($m[0] as $w) {
                $tokens[] = $w;
            }
        }

        // 中文關鍵字字典（示意嵌入會學到的語意單位）
        $keywords = ['退款', '退錢', '取消', '改訂', '變更', '日期', '入住', '付款', '信用卡',
            '全額', '不可退', '上限', '人工', '審核', '工作天', '房型', '差額', '政策'];
        foreach ($keywords as $kw) {
            if (strpos($text, $kw) !== false) {
                $tokens[] = $kw;
            }
        }

        return array_values(array_unique($tokens));
    }
}
