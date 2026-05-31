<?php
declare(strict_types=1);

/**
 * Top-K（Heavy Hitters）— 維護目前最熱門的 K 首歌
 * --------------------------------------------------
 * 對應真實元件：串流聚合器裡，搭配 Count-Min Sketch 一起跑的 min-heap。
 *
 * 常見誤區：以為 Count-Min Sketch 自己就能出 Top-K。
 * 不行 —— sketch 只能「給一個 key 估頻率」，無法列舉「誰是前 K 名」。
 * 所以額外維護一個大小為 K 的候選集合（min-heap，堆頂是榜內最小者）：
 *   每次播放事件 → 用 sketch 估該歌最新頻率 → 嘗試把它放進 / 更新堆。
 * Count-Min Sketch + min-heap = 近似 Top-K（Heavy Hitters）的經典組合。
 *
 * 本實作用 SplMinHeap 維護「估計值最小者在堆頂」，方便淘汰；
 * 同時用一個 candidates 集合追蹤目前榜內歌曲，避免重複。
 */
final class TopK
{
    /** @var array<string,int> 候選歌 → 最近一次的 sketch 估計值 */
    private array $candidates = [];

    public function __construct(
        private CountMinSketch $sketch,
        private int $k = 10,
    ) {}

    /**
     * 餵入一次播放事件：
     *   1. 寫進 sketch（近似計數）
     *   2. 用 sketch 估出該歌最新頻率
     *   3. 嘗試維護到 Top-K 候選集合（Heavy Hitters）
     */
    public function offer(string $song, int $count = 1): void
    {
        $this->sketch->add($song, $count);
        $est = $this->sketch->estimate($song);

        // 已在候選集合：更新估計值即可
        if (isset($this->candidates[$song])) {
            $this->candidates[$song] = $est;
            return;
        }

        // 候選集合還沒滿：直接加入
        if (count($this->candidates) < $this->k) {
            $this->candidates[$song] = $est;
            return;
        }

        // 候選集合已滿：找出目前榜內最小者（min-heap 的堆頂概念）
        $minSong = null;
        $minVal = PHP_INT_MAX;
        foreach ($this->candidates as $s => $v) {
            if ($v < $minVal) {
                $minVal = $v;
                $minSong = $s;
            }
        }
        // 新歌估計值超過榜內最小者 → 汰換
        if ($minSong !== null && $est > $minVal) {
            unset($this->candidates[$minSong]);
            $this->candidates[$song] = $est;
        }
    }

    /**
     * 套用一次時間衰減：sketch 整體衰減後，重新用 sketch 估值刷新候選分數。
     * 這會讓「近期沒人播」的舊熱門歌分數下滑、可能跌出榜，反映趨勢變化。
     */
    public function decay(float $lambda): void
    {
        $this->sketch->decay($lambda);
        foreach (array_keys($this->candidates) as $song) {
            $this->candidates[$song] = $this->sketch->estimate($song);
        }
    }

    /** 取得目前 Top-K 榜（依估計值由高到低） */
    public function top(): array
    {
        // 用 SplMaxHeap 依分數排出名次（覆寫 compare 以分數為鍵）
        $heap = new class extends SplMaxHeap {
            protected function compare(mixed $a, mixed $b): int
            {
                return $a['score'] <=> $b['score'];
            }
        };
        foreach ($this->candidates as $song => $est) {
            $heap->insert(['song' => $song, 'score' => $est]);
        }
        $out = [];
        $rank = 1;
        foreach ($heap as $item) {
            $out[] = ['rank' => $rank++, 'song' => $item['song'], 'score' => $item['score']];
        }
        return $out;
    }

    public function k(): int { return $this->k; }

    /** 序列化狀態（候選集合；sketch 由外部一併保存） */
    public function candidatesArray(): array
    {
        return $this->candidates;
    }

    public function loadCandidates(array $candidates): void
    {
        $this->candidates = array_map('intval', $candidates);
    }
}
