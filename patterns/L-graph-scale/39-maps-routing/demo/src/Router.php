<?php
declare(strict_types=1);

/**
 * 路由引擎：Dijkstra 與 A*
 * --------------------------------------------------
 * 兩者都解「單源最短路」，差別只在「下一個展開哪個節點」的優先順序：
 *   - Dijkstra：依「已走成本 g」展開 → 向四面八方均勻擴張。
 *   - A*       ：依「g + 啟發式 h（到終點的樂觀估計）」展開 → 朝終點方向擴張。
 * 只要 h 可採納（永不高估真實剩餘成本），A* 找到的路徑與 Dijkstra 同樣最優，
 * 但「展開（explored / settled）的節點數」通常遠少於 Dijkstra —— 這是本題核心 demo。
 *
 * metric：
 *   'distance' → 最短路徑（公尺，不受路況影響）
 *   'time'     → 最快路徑（秒，邊權 = 自由流時間 × 壅塞係數，受即時路況影響 → ETA）
 */
final class Router
{
    public function __construct(private Graph $graph) {}

    /**
     * @return array{
     *   found:bool, algo:string, metric:string,
     *   path:int[], cost:float, explored:int, hops:int
     * }
     */
    public function route(int $from, int $to, string $algo, string $metric): array
    {
        if (!$this->graph->hasNode($from) || !$this->graph->hasNode($to)) {
            return $this->miss($algo, $metric);
        }
        $useHeuristic = ($algo === 'astar');

        // 優先佇列：以 [priority, node] 維護一個簡易二元堆積。
        $pq = new MinHeap();
        $g = [$from => 0.0];                 // 起點到節點的已知最佳成本
        $prev = [];                          // 回溯路徑
        $settled = [];                       // 已「定案」展開的節點（explored 計數來源）

        $h0 = $useHeuristic ? $this->graph->heuristic($from, $to, $metric) : 0.0;
        $pq->push($from, $h0);

        $explored = 0;
        while (!$pq->isEmpty()) {
            $u = $pq->pop();
            if (isset($settled[$u])) {
                continue;                    // 過期項目（堆積允許重複入列）
            }
            $settled[$u] = true;
            $explored++;

            if ($u === $to) {
                break;                       // 目標定案即可停（A* 的提早終止）
            }
            foreach ($this->graph->neighbors($u, $metric) as $v => $w) {
                if (isset($settled[$v])) {
                    continue;
                }
                $ng = $g[$u] + $w;
                if (!isset($g[$v]) || $ng < $g[$v]) {
                    $g[$v] = $ng;
                    $prev[$v] = $u;
                    $h = $useHeuristic ? $this->graph->heuristic($v, $to, $metric) : 0.0;
                    $pq->push($v, $ng + $h); // f = g + h
                }
            }
        }

        if (!isset($settled[$to]) && $from !== $to) {
            return $this->miss($algo, $metric, $explored);
        }

        // 回溯路徑
        $path = [];
        for ($cur = $to; $cur !== null; $cur = $prev[$cur] ?? null) {
            $path[] = $cur;
            if ($cur === $from) {
                break;
            }
        }
        $path = array_reverse($path);

        return [
            'found' => true,
            'algo' => $algo,
            'metric' => $metric,
            'path' => $path,
            'cost' => round($g[$to] ?? 0.0, 2),
            'explored' => $explored,
            'hops' => max(0, count($path) - 1),
        ];
    }

    /** 同時跑兩種演算法並比較探索節點數（測試頁主要展示） */
    public function compare(int $from, int $to, string $metric): array
    {
        $d = $this->route($from, $to, 'dijkstra', $metric);
        $a = $this->route($from, $to, 'astar', $metric);
        $saved = $d['explored'] > 0
            ? round((1 - $a['explored'] / $d['explored']) * 100, 1)
            : 0.0;
        return [
            'metric' => $metric,
            'dijkstra' => $d,
            'astar' => $a,
            'same_path' => $d['path'] === $a['path'],
            'same_cost' => abs($d['cost'] - $a['cost']) < 1e-6,
            'astar_explored_fewer' => $a['explored'] <= $d['explored'],
            'explored_saved_pct' => $saved,
        ];
    }

    private function miss(string $algo, string $metric, int $explored = 0): array
    {
        return [
            'found' => false, 'algo' => $algo, 'metric' => $metric,
            'path' => [], 'cost' => 0.0, 'explored' => $explored, 'hops' => 0,
        ];
    }
}

/**
 * 極簡二元最小堆積（避免外部依賴 / SplPriorityQueue 的最大堆積語意混淆）。
 * 允許同一節點以更好的優先度重複入列；pop 後靠 Router 的 settled 集合去重。
 */
final class MinHeap
{
    /** @var array<int,array{0:float,1:int}> [priority, node] */
    private array $h = [];

    public function isEmpty(): bool
    {
        return $this->h === [];
    }

    public function push(int $node, float $priority): void
    {
        $this->h[] = [$priority, $node];
        $i = count($this->h) - 1;
        while ($i > 0) {
            $p = intdiv($i - 1, 2);
            if ($this->h[$p][0] <= $this->h[$i][0]) {
                break;
            }
            [$this->h[$p], $this->h[$i]] = [$this->h[$i], $this->h[$p]];
            $i = $p;
        }
    }

    public function pop(): int
    {
        $top = $this->h[0][1];
        $last = array_pop($this->h);
        if ($this->h !== []) {
            $this->h[0] = $last;
            $this->siftDown(0);
        }
        return $top;
    }

    private function siftDown(int $i): void
    {
        $n = count($this->h);
        while (true) {
            $l = 2 * $i + 1;
            $r = 2 * $i + 2;
            $small = $i;
            if ($l < $n && $this->h[$l][0] < $this->h[$small][0]) {
                $small = $l;
            }
            if ($r < $n && $this->h[$r][0] < $this->h[$small][0]) {
                $small = $r;
            }
            if ($small === $i) {
                break;
            }
            [$this->h[$small], $this->h[$i]] = [$this->h[$i], $this->h[$small]];
            $i = $small;
        }
    }
}
