<?php
declare(strict_types=1);

/**
 * Bloom Filter（布隆過濾器）— 省記憶體的「可能存在 / 一定不存在」集合
 * =====================================================================
 * 用途：網頁爬蟲的 URL 去重。已爬過幾十億個 URL 時，把完整 URL 字串全放進 hash set
 * 會吃掉巨量記憶體；bloom filter 用「一個位元陣列 + k 個雜湊函式」以極小空間
 * 近似回答「這個 URL 我見過嗎？」。
 *
 * 核心性質（這正是面試考點）：
 *   - add(x)：把 x 經 k 個雜湊映到 k 個位元，全部設為 1。
 *   - mightContain(x)：檢查那 k 個位元是否「全為 1」。
 *       * 若有任一位為 0 → **一定沒加過**（沒有偽陰性，no false negative）。
 *       * 若全為 1 → **可能加過**（有偽陽性 false positive，因為別的元素也可能把這些位設 1）。
 *   - 偽陽性率 p ≈ (1 - e^(-k·n/m))^k，由位元數 m、雜湊數 k、已插入數 n 決定。
 *
 * 對爬蟲的意義：偽陽性代表「某個其實沒爬過的新 URL 被誤判為爬過 → 被跳過」，
 * 損失是少爬一頁（可接受）；換來的是用幾 GB 位元陣列代替幾百 GB 的精確集合。
 * 若要零漏爬，可在 mightContain 為真時再查一個權威來源（精確 seen 集合 / DB）二次確認，
 * 這正是本 demo 採用的策略：bloom 先擋掉絕大多數，命中再查精確 seen 確認。
 *
 * 實作限制：只用 hash() 與 crc32（禁用 mb_、gmp_、openssl_ 系列函式）。
 * k 個雜湊用「double hashing」：h_i(x) = (h1(x) + i*h2(x)) mod m，
 * 由兩個獨立雜湊組合出 k 個，省去算 k 次真雜湊。
 */
final class BloomFilter
{
    /** 位元陣列大小（位元數）。 */
    private int $m;
    /** 雜湊函式個數。 */
    private int $k;
    /** 位元陣列：用 PHP 字串當 bitset，每字元 8 位元。 */
    private string $bits;
    /** 已插入元素數（用來估算當前偽陽性率）。 */
    private int $inserted = 0;

    public function __construct(int $m = 1024, int $k = 4)
    {
        $this->m = max(8, $m);
        $this->k = max(1, $k);
        // 初始化全 0 位元陣列：ceil(m/8) 個 0x00 位元組。
        $this->bits = str_repeat("\0", intdiv($this->m + 7, 8));
    }

    /** 從持久化陣列還原（state 跨請求保存）。 */
    public static function fromArray(array $a): self
    {
        $bf = new self((int) ($a['m'] ?? 1024), (int) ($a['k'] ?? 4));
        $bf->inserted = (int) ($a['inserted'] ?? 0);
        $raw = (string) ($a['bits'] ?? '');
        // bits 用 base64 字串保存（JSON 不適合放二進位）。
        $decoded = $raw === '' ? '' : (string) base64_decode($raw, true);
        if ($decoded !== '' && strlen($decoded) === strlen($bf->bits)) {
            $bf->bits = $decoded;
        }
        return $bf;
    }

    /** 序列化成可存 JSON 的陣列。 */
    public function toArray(): array
    {
        return [
            'm' => $this->m,
            'k' => $this->k,
            'inserted' => $this->inserted,
            'bits' => base64_encode($this->bits),
        ];
    }

    /** 把元素加入過濾器（把 k 個位元設為 1）。 */
    public function add(string $item): void
    {
        foreach ($this->positions($item) as $pos) {
            $this->setBit($pos);
        }
        $this->inserted++;
    }

    /**
     * 可能包含？
     *   回 false → 一定沒加過（可安全當作全新 URL）。
     *   回 true  → 可能加過（需再查精確集合確認，因為可能是偽陽性）。
     */
    public function mightContain(string $item): bool
    {
        foreach ($this->positions($item) as $pos) {
            if (!$this->getBit($pos)) {
                return false; // 有一位為 0 → 一定沒加過（無偽陰性）
            }
        }
        return true;
    }

    /** 目前已設為 1 的位元數（觀察飽和程度）。 */
    public function setBitsCount(): int
    {
        $count = 0;
        $len = strlen($this->bits);
        for ($i = 0; $i < $len; $i++) {
            $count += $this->popcount(ord($this->bits[$i]));
        }
        return $count;
    }

    /** 統計資訊：含理論偽陽性率估算。 */
    public function stats(): array
    {
        $n = $this->inserted;
        $m = $this->m;
        $k = $this->k;
        // p ≈ (1 - e^(-k n / m))^k
        $p = $n === 0 ? 0.0 : (1 - exp(-1.0 * $k * $n / $m)) ** $k;
        return [
            'm_bits' => $m,
            'k_hashes' => $k,
            'inserted' => $n,
            'set_bits' => $this->setBitsCount(),
            'fill_ratio' => round($this->setBitsCount() / $m, 4),
            'false_positive_rate' => round($p, 5),
        ];
    }

    // ============================================================
    // 內部：雜湊與位元操作
    // ============================================================

    /** 由 double hashing 產生 k 個位元位置。 */
    private function positions(string $item): array
    {
        // 兩個獨立雜湊：crc32 與 fnv1a32（皆 32 位無號）。
        $h1 = crc32($item);
        $h2 = $this->fnv1a32($item);
        if ($h2 === 0) {
            $h2 = 0x9e3779b1; // 避免 step 為 0 導致 k 個位置全相同
        }
        $out = [];
        for ($i = 0; $i < $this->k; $i++) {
            // (h1 + i*h2) mod m；用浮點避免 32 位溢位後取模偏差。
            $combined = ($h1 + $i * $h2) % $this->m;
            $out[] = $combined;
        }
        return $out;
    }

    /** FNV-1a 32 位雜湊（純整數運算，零依賴）。 */
    private function fnv1a32(string $s): int
    {
        $hash = 0x811c9dc5;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $hash ^= ord($s[$i]);
            // FNV prime 16777619；用模 2^32 保持 32 位。
            $hash = ($hash * 0x01000193) & 0xffffffff;
        }
        return $hash;
    }

    private function setBit(int $pos): void
    {
        $byte = intdiv($pos, 8);
        $bit = $pos % 8;
        $this->bits[$byte] = chr(ord($this->bits[$byte]) | (1 << $bit));
    }

    private function getBit(int $pos): bool
    {
        $byte = intdiv($pos, 8);
        $bit = $pos % 8;
        return (ord($this->bits[$byte]) & (1 << $bit)) !== 0;
    }

    private function popcount(int $b): int
    {
        $c = 0;
        while ($b) {
            $c += $b & 1;
            $b >>= 1;
        }
        return $c;
    }
}
