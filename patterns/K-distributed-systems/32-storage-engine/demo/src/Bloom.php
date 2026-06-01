<?php
declare(strict_types=1);

/**
 * Bloom Filter（布隆過濾器）
 * --------------------------------------------------
 * 一個機率型集合：能極省空間地回答「這個 key 一定不在」或「可能在」。
 *
 * 為何 LSM-tree 需要它？
 *   讀取一個 key 時，要從新到舊掃多個 SSTable 檔。若每個檔都得開檔做磁碟 I/O
 *   才知道「裡面根本沒這個 key」，讀放大會很嚴重。
 *   每個 SSTable 配一個 bloom filter（常駐記憶體）：
 *     - mightContain()==false  → 100% 確定不在此檔 → 直接跳過，省一次磁碟 I/O。
 *     - mightContain()==true   → 可能在（也可能偽陽性）→ 才真的去查該檔。
 *
 * 特性：
 *   - 不會偽陰性（說沒有就一定沒有）。
 *   - 會偽陽性（說可能有，實際可能沒有）；偽陽率隨位元數 m、雜湊數 k、元素數 n 變化。
 *
 * 本實作只用 PHP 內建 crc32 / hash()，位元陣列用整數陣列模擬（每元素存 32 bit）。
 */
final class Bloom
{
    /** @var int 位元總數 */
    private int $m;
    /** @var int 雜湊函式個數 */
    private int $k;
    /** @var array<int,int> 位元陣列，每個元素存 32 個 bit */
    private array $bits;

    public function __construct(int $m = 1024, int $k = 4)
    {
        $this->m = max(8, $m);
        $this->k = max(1, $k);
        $this->bits = array_fill(0, intdiv($this->m, 32) + 1, 0);
    }

    /** 加入一個 key：把 k 個雜湊位置的 bit 設為 1 */
    public function add(string $key): void
    {
        foreach ($this->positions($key) as $pos) {
            $idx = intdiv($pos, 32);
            $off = $pos % 32;
            $this->bits[$idx] |= (1 << $off);
        }
    }

    /**
     * 查詢：只要有任一 bit 為 0 → 一定不在（回 false）。
     * 全部為 1 → 可能在（回 true，含偽陽性）。
     */
    public function mightContain(string $key): bool
    {
        foreach ($this->positions($key) as $pos) {
            $idx = intdiv($pos, 32);
            $off = $pos % 32;
            if (($this->bits[$idx] & (1 << $off)) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * 用「雙重雜湊」由兩個基底雜湊推導出 k 個位置：
     *   pos_i = (h1 + i * h2) mod m
     * 這是 Kirsch-Mitzenmacher 技巧，免得真的算 k 個獨立雜湊。
     *
     * @return list<int>
     */
    private function positions(string $key): array
    {
        // 基底雜湊 1：crc32（任務限定可用）
        $h1 = crc32($key);
        // 基底雜湊 2：hash() 取 fnv1a32 的 16 進位再轉整數
        $h2 = (int) hexdec(substr(hash('fnv1a32', $key), 0, 8));
        if ($h2 === 0) {
            $h2 = 0x9E3779B1; // 避免步長為 0
        }
        $out = [];
        for ($i = 0; $i < $this->k; $i++) {
            $combined = ($h1 + $i * $h2) & 0x7FFFFFFF; // 取非負
            $out[] = $combined % $this->m;
        }
        return $out;
    }

    /** 序列化為可存進 SSTable 的陣列 */
    public function toArray(): array
    {
        return ['m' => $this->m, 'k' => $this->k, 'bits' => $this->bits];
    }

    /** 由 SSTable 載回 */
    public static function fromArray(array $data): self
    {
        $b = new self((int) $data['m'], (int) $data['k']);
        $b->bits = array_map('intval', $data['bits']);
        return $b;
    }
}
