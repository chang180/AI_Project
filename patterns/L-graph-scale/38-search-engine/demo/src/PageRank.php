<?php
declare(strict_types=1);

/**
 * PageRank — 在文件連結圖上用「冪次迭代」算每頁的權威分數
 * --------------------------------------------------
 * 真實搜尋引擎（Google 原始論文）：把整個 Web 看成一張有向圖，
 * 網頁 = 節點、超連結 = 有向邊。一頁被越多「高權威」頁指到，自己就越權威。
 * 數學上是求轉移矩陣的主特徵向量，工程上用冪次迭代（power iteration）逼近：
 *
 *   PR(p) = (1 - d) / N  +  d * Σ_{q→p}  PR(q) / outDeg(q)
 *
 *   d        = damping factor（阻尼係數，慣例 0.85）＝隨機衝浪者「沿連結走」的機率，
 *              (1-d) ＝ 厭倦後「隨機跳到任一頁」的機率。
 *   N        = 總頁數。
 *   q→p      = 所有指向 p 的頁 q。
 *   outDeg(q)= q 的出連結數（把自己的分數平均分給它連到的每一頁）。
 *
 * dangling node（無出連結的頁，outDeg=0）：它的分數無處可流會「漏掉」，
 * 處理法是把 dangling 質量收集起來，再平均灑回所有頁（等價於它連向全圖）。
 *
 * 收斂：反覆迭代到分數變化（L1 距離）小於 epsilon，或達 maxIter 上限。
 * 純 PHP 浮點運算，零依賴。
 */
final class PageRank
{
    public function __construct(
        private float $damping = 0.85,
        private float $epsilon = 1.0e-8,
        private int $maxIter = 100,
    ) {}

    /**
     * 計算 PageRank。
     *
     * @param array<int,int[]> $graph  docId → 它連出去的 docId 陣列（鄰接表）
     * @return array{scores:array<int,float>,iterations:int,converged:bool,
     *               damping:float,danglingCount:int}
     */
    public function compute(array $graph): array
    {
        // 收集所有節點（連結目標可能指到尚未出現在鍵中的頁，全部納入）
        $nodes = [];
        foreach ($graph as $src => $outs) {
            $nodes[$src] = true;
            foreach ($outs as $dst) {
                $nodes[$dst] = true;
            }
        }
        $ids = array_keys($nodes);
        $N = count($ids);
        if ($N === 0) {
            return [
                'scores' => [], 'iterations' => 0, 'converged' => true,
                'damping' => $this->damping, 'danglingCount' => 0,
            ];
        }

        // 反向鄰接表：dst → [src...]，方便「Σ_{q→p}」累加
        $inbound = [];
        $outDeg = [];
        $dangling = [];
        foreach ($ids as $id) {
            $inbound[$id] = [];
            $outDeg[$id] = 0;
        }
        foreach ($graph as $src => $outs) {
            // 去重 + 去自迴圈（自己連自己對權威無意義）
            $seen = [];
            foreach ($outs as $dst) {
                if ($dst === $src || isset($seen[$dst]) || !isset($nodes[$dst])) {
                    continue;
                }
                $seen[$dst] = true;
                $inbound[$dst][] = $src;
                $outDeg[$src]++;
            }
        }
        foreach ($ids as $id) {
            if ($outDeg[$id] === 0) {
                $dangling[] = $id; // 無出連結 → dangling node
            }
        }

        // 初始：均勻分布 PR = 1/N
        $pr = [];
        foreach ($ids as $id) {
            $pr[$id] = 1.0 / $N;
        }

        $d = $this->damping;
        $base = (1.0 - $d) / $N;
        $iterations = 0;
        $converged = false;

        for ($iter = 0; $iter < $this->maxIter; $iter++) {
            $iterations = $iter + 1;

            // dangling 質量：這些頁的分數無處流，集中起來平均灑回全圖
            $danglingMass = 0.0;
            foreach ($dangling as $id) {
                $danglingMass += $pr[$id];
            }
            $danglingShare = $d * $danglingMass / $N;

            $next = [];
            $diff = 0.0;
            foreach ($ids as $p) {
                $sum = 0.0;
                foreach ($inbound[$p] as $q) {
                    // q 把自己的分數平均分給它連到的每一頁
                    $sum += $pr[$q] / $outDeg[$q];
                }
                $next[$p] = $base + $danglingShare + $d * $sum;
                $diff += abs($next[$p] - $pr[$p]);
            }
            $pr = $next;

            if ($diff < $this->epsilon) {
                $converged = true;
                break;
            }
        }

        // 排名好讀：由高到低
        arsort($pr);
        return [
            'scores' => $pr,
            'iterations' => $iterations,
            'converged' => $converged,
            'damping' => $d,
            'danglingCount' => count($dangling),
        ];
    }
}
