<?php
declare(strict_types=1);

/**
 * Count-Min Sketch — 近似頻率計數
 * --------------------------------------------------
 * 對應真實元件：串流聚合器（Flink）裡用來估「每首歌播放次數」的機率資料結構。
 *
 * 為什麼用它：精確 hash map 的記憶體隨「不同歌曲數（distinct key）」線性成長，
 * 1 億首歌就要 GB 級且不可控；Count-Min Sketch 的記憶體只看 w（寬度）× d（深度），
 * 與歌曲數無關，固定 O(w·d)。代價是計數「只會偏大」的有界誤差。
 *
 *   結構：d 列 × w 欄的整數陣列 + d 個獨立雜湊。
 *   add(key, n)   ：每一列各取一格 += n（共 d 次遞增）。
 *   estimate(key) ：取 d 格中的「最小值」（碰撞只會讓計數偏大，取 min 最接近真值）。
 *   誤差保證：估計值 ≥ 真值，超出量以高機率 ≤ ε·N（ε ≈ e/w）。
 *   合併：同 w、d、雜湊的兩個 sketch 可逐格相加 → 天然可分散式聚合。
 */
final class CountMinSketch
{
    /** @var int[][] d 列 × w 欄的計數矩陣 */
    private array $table;

    /** @var int[] 每一列的雜湊種子（讓 d 個雜湊彼此獨立） */
    private array $seeds;

    public function __construct(
        private int $width = 1024,   // w：每列格數，越大誤差越小
        private int $depth = 5,      // d：列數，越多信心越高
    ) {
        $this->table = [];
        $this->seeds = [];
        for ($i = 0; $i < $this->depth; $i++) {
            $this->table[$i] = array_fill(0, $this->width, 0);
            // 用不同種子做出 d 個獨立雜湊
            $this->seeds[$i] = ($i + 1) * 0x9E3779B1;
        }
    }

    /** 第 i 列、key 對應的欄索引 */
    private function indexOf(int $row, string $key): int
    {
        // crc32 + 種子混合，再對 width 取模 → 0..width-1
        $h = crc32($key . '|' . $this->seeds[$row]);
        return $h % $this->width;
    }

    /** 計入一次（或多次）播放事件 */
    public function add(string $key, int $count = 1): void
    {
        for ($i = 0; $i < $this->depth; $i++) {
            $this->table[$i][$this->indexOf($i, $key)] += $count;
        }
    }

    /** 近似頻率：取 d 格最小值（保證 ≥ 真值，取 min 最接近真值） */
    public function estimate(string $key): int
    {
        $min = PHP_INT_MAX;
        for ($i = 0; $i < $this->depth; $i++) {
            $v = $this->table[$i][$this->indexOf($i, $key)];
            if ($v < $min) {
                $min = $v;
            }
        }
        return $min === PHP_INT_MAX ? 0 : $min;
    }

    /**
     * 指數時間衰減：每一輪把整個矩陣乘上衰減係數 λ（如 0.9）。
     * 效果：舊播放權重指數式淡出，榜單偏向「近期竄升」→ 趨勢榜的核心。
     */
    public function decay(float $lambda): void
    {
        for ($i = 0; $i < $this->depth; $i++) {
            for ($j = 0; $j < $this->width; $j++) {
                if ($this->table[$i][$j] > 0) {
                    $this->table[$i][$j] = (int) floor($this->table[$i][$j] * $lambda);
                }
            }
        }
    }

    /** 記憶體用量示意：固定 w×d，與歌曲數無關 */
    public function cells(): int
    {
        return $this->width * $this->depth;
    }

    public function width(): int { return $this->width; }
    public function depth(): int { return $this->depth; }

    /** 序列化狀態（供 EventStore 持久化串流算子狀態） */
    public function toArray(): array
    {
        return ['width' => $this->width, 'depth' => $this->depth, 'table' => $this->table];
    }

    /** 從持久化狀態還原 */
    public static function fromArray(array $data): self
    {
        $s = new self((int) $data['width'], (int) $data['depth']);
        if (isset($data['table']) && is_array($data['table'])) {
            $s->table = array_map(
                static fn($row) => array_map('intval', $row),
                $data['table']
            );
        }
        return $s;
    }
}
