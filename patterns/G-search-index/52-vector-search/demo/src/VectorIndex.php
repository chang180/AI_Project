<?php
declare(strict_types=1);

require_once __DIR__ . '/Similarity.php';

/**
 * 向量索引：暴力 kNN（基準）vs ANN 近似（IVF 倒排檔）
 * ==================================================================
 * 這是本題的核心考點。給一個查詢向量 q，要找出「最相似的 k 筆」。
 *
 *  ① 暴力 kNN（brute force）：
 *     算 q 對「全部 N 筆」的相似度 → 排序 → 取前 k。
 *     結果保證最正確（召回率 100%），但要掃 N 筆，N 大就慢。當作正確答案的基準。
 *
 *  ② ANN 近似（IVF, Inverted File / 倒排檔）：
 *     a. 建索引：把所有向量分成 nlist 群（每群一個 centroid，用簡化 k-means）。
 *     b. 查詢：先比 q 對「各 centroid」的相似度，只挑最近的 nprobe 個群，
 *        然後「只在這幾個群裡」的向量做精算 → 排序取前 k。
 *     → 掃描量從 N 降到「那幾個群裡的向量數 M」（M << N），快很多；
 *        但可能漏掉「真正最近卻落在沒被探查的群」的向量 → 召回率 < 100%。
 *
 *  回報「暴力掃 N 個 vs ANN 只掃 M 個」與兩者結果差異 → 速度 / 召回率取捨。
 *
 * 零依賴：僅用陣列與基本數學。索引在記憶體中建（每次查詢重建，demo 規模小）。
 */
final class VectorIndex
{
    /** @var array<int,array{id:int,vec:float[],meta:array}> */
    private array $items;
    private string $metric;
    /** @var array<int,float[]> centroid 向量 */
    private array $centroids = [];
    /** @var array<int,int[]> cluster id → 該群的 item index（倒排檔） */
    private array $invlists = [];
    private int $nlist;

    /**
     * @param array<int,array{id:int,vec:float[],meta:array}> $items
     */
    public function __construct(array $items, string $metric = 'cosine', int $nlist = 0)
    {
        $this->items = array_values($items);
        $this->metric = $metric;
        $n = count($this->items);
        // nlist 預設取 ~sqrt(N)（業界常用經驗值），至少 1
        $this->nlist = $nlist > 0 ? $nlist : max(1, (int) round(sqrt(max(1, $n))));
        $this->buildIvf();
    }

    /**
     * 暴力 kNN：掃全部 N 筆，回正確答案 + 掃描數。
     * @param float[] $q
     * @return array{results:array<int,array>,scanned:int}
     */
    public function bruteKnn(array $q, int $k): array
    {
        $scored = [];
        foreach ($this->items as $it) {
            $scored[] = [
                'id'    => $it['id'],
                'meta'  => $it['meta'],
                'score' => Similarity::score($q, $it['vec'], $this->metric),
            ];
        }
        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        return [
            'results' => array_slice($scored, 0, $k),
            'scanned' => count($this->items), // 暴力 = 掃 N 個
        ];
    }

    /**
     * ANN（IVF）：只比最近 nprobe 個群裡的向量。
     * @param float[] $q
     * @return array{results:array<int,array>,scanned:int,probed:int[],nlist:int}
     */
    public function annKnn(array $q, int $k, int $nprobe = 1): array
    {
        // 1) q 對各 centroid 算相似度，挑最近的 nprobe 個群
        $cscore = [];
        foreach ($this->centroids as $cid => $cvec) {
            $cscore[$cid] = Similarity::score($q, $cvec, $this->metric);
        }
        arsort($cscore); // 由相似到不相似
        $probe = array_slice(array_keys($cscore), 0, max(1, $nprobe));

        // 2) 只在被探查的群裡精算
        $scored = [];
        $scanned = 0;
        foreach ($probe as $cid) {
            foreach ($this->invlists[$cid] ?? [] as $idx) {
                $it = $this->items[$idx];
                $scored[] = [
                    'id'    => $it['id'],
                    'meta'  => $it['meta'],
                    'score' => Similarity::score($q, $it['vec'], $this->metric),
                ];
                $scanned++;
            }
        }
        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        return [
            'results' => array_slice($scored, 0, $k),
            'scanned' => $scanned,          // ANN = 只掃 M 個（M ≤ N）
            'probed'  => $probe,
            'nlist'   => $this->nlist,
        ];
    }

    /** 召回率：ANN 命中的 id 佔暴力答案 id 的比例（top-k 交集 / k） */
    public static function recall(array $bruteResults, array $annResults): float
    {
        $truth = array_map(static fn($r) => $r['id'], $bruteResults);
        if (!$truth) {
            return 1.0;
        }
        $got = array_map(static fn($r) => $r['id'], $annResults);
        $hit = count(array_intersect($truth, $got));
        return $hit / count($truth);
    }

    public function nlist(): int
    {
        return $this->nlist;
    }

    // ---------------- IVF 建索引（簡化 k-means） ----------------

    private function buildIvf(): void
    {
        $n = count($this->items);
        if ($n === 0) {
            $this->centroids = [];
            $this->invlists = [];
            return;
        }
        $dim = count($this->items[0]['vec']);

        // 初始化 centroid：等距取樣（取代隨機，讓 demo 可重現）
        $this->centroids = [];
        for ($c = 0; $c < $this->nlist; $c++) {
            $pick = (int) floor($c * $n / $this->nlist);
            $this->centroids[$c] = $this->items[$pick]['vec'];
        }

        // 跑幾輪 Lloyd（指派 → 重算中心）。demo 規模小，5 輪足夠收斂。
        $assign = array_fill(0, $n, 0);
        for ($iter = 0; $iter < 5; $iter++) {
            // 指派：每個點歸到最相似的 centroid
            for ($i = 0; $i < $n; $i++) {
                $best = 0;
                $bestScore = -INF;
                foreach ($this->centroids as $cid => $cvec) {
                    $s = Similarity::score($this->items[$i]['vec'], $cvec, $this->metric);
                    if ($s > $bestScore) {
                        $bestScore = $s;
                        $best = $cid;
                    }
                }
                $assign[$i] = $best;
            }
            // 重算中心：群內向量逐維平均
            $sum = [];
            $cnt = [];
            for ($c = 0; $c < $this->nlist; $c++) {
                $sum[$c] = array_fill(0, $dim, 0.0);
                $cnt[$c] = 0;
            }
            for ($i = 0; $i < $n; $i++) {
                $c = $assign[$i];
                $cnt[$c]++;
                foreach ($this->items[$i]['vec'] as $d => $val) {
                    $sum[$c][$d] += $val;
                }
            }
            for ($c = 0; $c < $this->nlist; $c++) {
                if ($cnt[$c] > 0) {
                    foreach ($sum[$c] as $d => $val) {
                        $this->centroids[$c][$d] = $val / $cnt[$c];
                    }
                }
                // 空群保留原 centroid，不更新
            }
        }

        // 建倒排檔：cluster → 成員 index
        $this->invlists = [];
        for ($c = 0; $c < $this->nlist; $c++) {
            $this->invlists[$c] = [];
        }
        for ($i = 0; $i < $n; $i++) {
            $this->invlists[$assign[$i]][] = $i;
        }
    }
}
