<?php
declare(strict_types=1);

/**
 * 推薦系統核心：兩階段架構（召回 candidate generation → 排序 ranking）
 * ============================================================
 * 線上一次推薦請求的真實流程：
 *
 *   1) 召回（候選生成）：從數萬~數百萬 item 中「快速、粗略」撈出數百個候選。
 *      - 協同過濾（item-based CF）：由 user 互動過的 item，找共現/相似的 item。
 *      - embedding ANN（近似最近鄰）：每個 item 一個小向量，用內積找最近鄰。
 *      兩路召回的候選「聯集」後進入排序。
 *
 *   2) 排序（精排）：對候選用多特徵加權打分（熱門度、與 user 的相似度、新鮮度…），
 *      取 Top-N 回傳。召回求「廣度與速度」，排序求「精度」。
 *
 *   3) 冷啟動：新 user 沒有互動 → 無法做個人化召回 → 退回熱門 item（popularity fallback）。
 *
 * 本檔的協同過濾、兩階段召回排序、冷啟動皆為「真實可執行邏輯」；
 * 只是規模小、未用真模型訓練的 embedding，也未用真正的 ANN 索引（HNSW/IVF）。
 */
final class Recommender
{
    /** 互動類型 → 隱性評分權重（buy 比 view 強烈得多） */
    private const TYPE_WEIGHT = ['view' => 1.0, 'click' => 2.0, 'buy' => 5.0];

    public function __construct(private Store $store) {}

    // =====================================================================
    // 對外主入口：對某 user 取 Top-N 推薦，回傳含「兩階段中間結果」以便教學展示
    // =====================================================================
    /**
     * @return array{cold_start:bool, recall:array, ranked:list<array>, topN:list<array>}
     */
    public function recommend(string $user, int $n = 5): array
    {
        $userItems = $this->userItemScores($user); // 此 user 互動過的 item => 權重分

        // ---- 冷啟動：無任何互動 → 回熱門 ----
        if ($userItems === []) {
            $pop = $this->popularItems($n);
            return [
                'cold_start' => true,
                'recall'     => ['cf' => [], 'ann' => [], 'merged' => array_column($pop, 'item')],
                'ranked'     => $pop,
                'topN'       => array_slice($pop, 0, $n),
            ];
        }

        // ---- 階段 1：召回（兩路） ----
        $cfCand  = $this->recallByCF($userItems);          // 協同過濾候選 => 召回分
        $annCand = $this->recallByANN($userItems);         // embedding ANN 候選 => 召回分

        // 聯集（同一 item 取較高的召回分），並排除使用者已互動過的 item
        $merged = $cfCand;
        foreach ($annCand as $item => $score) {
            $merged[$item] = max($merged[$item] ?? 0.0, $score);
        }
        foreach (array_keys($userItems) as $seen) {
            unset($merged[$seen]);
        }

        // ---- 階段 2：排序（精排） ----
        $ranked = $this->rank($user, $userItems, $merged);

        return [
            'cold_start' => false,
            'recall'     => [
                'cf'     => $cfCand,
                'ann'    => $annCand,
                'merged' => array_keys($merged),
            ],
            'ranked'     => $ranked,
            'topN'       => array_slice($ranked, 0, $n),
        ];
    }

    // =====================================================================
    // 召回 A：協同過濾（item-based CF）
    //   - 先由全體互動建 item-item 共現相似度（cosine）。
    //   - 對 user 互動過的每個 item，取其相似 item 當候選，
    //     候選召回分 = Σ(使用者對來源 item 的權重 × 來源→候選的相似度)。
    // =====================================================================
    /**
     * @param array<string,float> $userItems
     * @return array<string,float> 候選 item => 召回分
     */
    public function recallByCF(array $userItems): array
    {
        $sim = $this->itemItemSimilarity();
        $cand = [];
        foreach ($userItems as $src => $w) {
            foreach (($sim[$src] ?? []) as $other => $s) {
                $cand[$other] = ($cand[$other] ?? 0.0) + $w * $s;
            }
        }
        arsort($cand);
        return $cand;
    }

    /**
     * item-item 相似度矩陣（cosine on user-item 隱性評分向量）
     * 兩個 item 若常被「同一批 user」互動，相似度就高。
     * @return array<string,array<string,float>> srcItem => (otherItem => cosine)
     */
    public function itemItemSimilarity(): array
    {
        // 建 item => (user => 權重) 向量
        $vec = [];
        foreach ($this->store->events() as $ev) {
            $w = self::TYPE_WEIGHT[$ev['type']] ?? 1.0;
            // 同一 (user,item) 取最大權重（buy 蓋過 view）
            $vec[$ev['item']][$ev['user']] = max($vec[$ev['item']][$ev['user']] ?? 0.0, $w);
        }

        $items = array_keys($vec);
        $sim = [];
        foreach ($items as $a) {
            foreach ($items as $b) {
                if ($a === $b) {
                    continue;
                }
                $c = $this->cosine($vec[$a], $vec[$b]);
                if ($c > 0.0) {
                    $sim[$a][$b] = round($c, 4);
                }
            }
            if (isset($sim[$a])) {
                arsort($sim[$a]);
            }
        }
        return $sim;
    }

    // =====================================================================
    // 召回 B：embedding + ANN（近似最近鄰）
    //   - 每個 item 給一個小向量（這裡用 category 的 one-hot 當示意 embedding；
    //     真實系統是訓練出來的稠密向量）。
    //   - 把 user 互動過的 item 向量加權平均成「user 向量」，
    //     用內積找最近鄰 item 當候選（簡化版 ANN，這裡是暴力全掃；
    //     真實系統用 HNSW/IVF 索引把 O(N) 降到近似 O(log N)）。
    // =====================================================================
    /**
     * @param array<string,float> $userItems
     * @return array<string,float> 候選 item => 內積相似度
     */
    public function recallByANN(array $userItems): array
    {
        $emb = $this->itemEmbeddings();
        // user 向量 = Σ 權重 × item 向量
        $uvec = [];
        foreach ($userItems as $item => $w) {
            foreach (($emb[$item] ?? []) as $dim => $v) {
                $uvec[$dim] = ($uvec[$dim] ?? 0.0) + $w * $v;
            }
        }
        if ($uvec === []) {
            return [];
        }
        $cand = [];
        foreach ($emb as $item => $vec) {
            $dot = 0.0;
            foreach ($vec as $dim => $v) {
                $dot += ($uvec[$dim] ?? 0.0) * $v;
            }
            if ($dot > 0.0) {
                $cand[$item] = round($dot, 4);
            }
        }
        arsort($cand);
        return $cand;
    }

    /**
     * item embedding（示意：用 category one-hot；真實系統為訓練出的稠密向量）
     * @return array<string,array<string,float>>
     */
    public function itemEmbeddings(): array
    {
        $emb = [];
        foreach ($this->store->items() as $id => $meta) {
            $cat = (string) ($meta['category'] ?? 'misc');
            $emb[$id] = [$cat => 1.0]; // 同類 item 內積為 1，跨類為 0
        }
        return $emb;
    }

    // =====================================================================
    // 排序（精排）：多特徵加權打分
    //   score = w1·召回分(正規化) + w2·與user相似度 + w3·熱門度(正規化) + w4·新鮮度
    // =====================================================================
    /**
     * @param array<string,float> $userItems
     * @param array<string,float> $candidates 候選 => 召回分
     * @return list<array> 每個元素含 item 中繼資料與各項特徵分與總分（已排序）
     */
    public function rank(string $user, array $userItems, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $sim = $this->itemItemSimilarity();
        $maxRecall = max($candidates) ?: 1.0;
        $items = $this->store->items();
        $maxPop = 1.0;
        foreach ($items as $m) {
            $maxPop = max($maxPop, (float) ($m['popularity'] ?? 0));
        }
        $now = time();

        $rows = [];
        foreach ($candidates as $item => $recallScore) {
            $meta = $items[$item] ?? null;
            if ($meta === null) {
                continue;
            }
            // 特徵 1：召回分（正規化 0~1）
            $fRecall = $recallScore / $maxRecall;
            // 特徵 2：與 user 的相似度（候選 item 對 user 已互動 item 的加權相似）
            $fUserSim = 0.0;
            foreach ($userItems as $src => $w) {
                $fUserSim += $w * ($sim[$src][$item] ?? 0.0);
            }
            $fUserSim = min(1.0, $fUserSim / 10.0);
            // 特徵 3：熱門度（正規化）
            $fPop = (float) ($meta['popularity'] ?? 0) / $maxPop;
            // 特徵 4：新鮮度（越新越高，半衰 90 天）
            $ageDays = max(0.0, ($now - (int) ($meta['created_at'] ?? $now)) / 86400);
            $fFresh = 1.0 / (1.0 + $ageDays / 90.0);

            $score = 0.45 * $fRecall + 0.30 * $fUserSim + 0.15 * $fPop + 0.10 * $fFresh;

            $rows[] = [
                'item'     => $item,
                'title'    => $meta['title'] ?? $item,
                'category' => $meta['category'] ?? '',
                'score'    => round($score, 4),
                'features' => [
                    'recall'   => round($fRecall, 3),
                    'user_sim' => round($fUserSim, 3),
                    'pop'      => round($fPop, 3),
                    'fresh'    => round($fFresh, 3),
                ],
            ];
        }
        usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
        return $rows;
    }

    // =====================================================================
    // 冷啟動 fallback：熱門 item（不需個人化）
    // =====================================================================
    /** @return list<array> 熱門 item（含 score=正規化熱門度） */
    public function popularItems(int $n): array
    {
        $items = $this->store->items();
        $maxPop = 1.0;
        foreach ($items as $m) {
            $maxPop = max($maxPop, (float) ($m['popularity'] ?? 0));
        }
        $rows = [];
        foreach ($items as $id => $meta) {
            $rows[] = [
                'item'     => $id,
                'title'    => $meta['title'] ?? $id,
                'category' => $meta['category'] ?? '',
                'score'    => round((float) ($meta['popularity'] ?? 0) / $maxPop, 4),
                'features' => ['pop' => round((float) ($meta['popularity'] ?? 0) / $maxPop, 3)],
            ];
        }
        usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($rows, 0, $n);
    }

    // =====================================================================
    // 工具
    // =====================================================================
    /** 此 user 互動過的 item => 隱性評分（取最大權重） */
    public function userItemScores(string $user): array
    {
        $out = [];
        foreach ($this->store->events() as $ev) {
            if ($ev['user'] !== $user) {
                continue;
            }
            $w = self::TYPE_WEIGHT[$ev['type']] ?? 1.0;
            $out[$ev['item']] = max($out[$ev['item']] ?? 0.0, $w);
        }
        return $out;
    }

    /**
     * @param array<string,float> $a
     * @param array<string,float> $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $k => $v) {
            if (isset($b[$k])) {
                $dot += $v * $b[$k];
            }
        }
        if ($dot === 0.0) {
            return 0.0;
        }
        $na = 0.0;
        foreach ($a as $v) {
            $na += $v * $v;
        }
        $nb = 0.0;
        foreach ($b as $v) {
            $nb += $v * $v;
        }
        $den = sqrt($na) * sqrt($nb);
        return $den > 0.0 ? $dot / $den : 0.0;
    }
}
