<?php
declare(strict_types=1);

/**
 * 短碼產生器
 * --------------------------------------------------
 * 模擬面試首選方案：KGS（Key Generation Service）發號 + 打亂。
 *  - next()：從 Store 取得單調遞增的全域 ID（真實系統會由 KGS 批次預領一段範圍，
 *            這裡用 Store 的計數器模擬）。
 *  - encode()：base62 編碼。為避免 abc123 / abc124 相鄰被遍歷，
 *            先用「乘法雜湊（Knuth）」把 ID 打亂成不可預測但仍為雙射的數字，再編碼。
 */
final class ShortCode
{
    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'; // base62
    // 64 位元乘法雜湊常數（與 2^64 互質，保證雙射、可逆）
    private const KNUTH = 6364136223846793005;

    public function __construct(private Store $store) {}

    /** 產生下一個唯一短碼 */
    public function next(): string
    {
        $id = $this->store->nextId();          // 全域唯一遞增 ID
        $scrambled = $this->scramble($id);     // 打亂 → 不可預測
        return $this->encode($scrambled);
    }

    /** 乘法雜湊打亂（模 2^64），用 PHP 的無號乘法行為近似 */
    private function scramble(int $id): int
    {
        // 用 gmp 確保 64 位元環繞乘法正確
        if (extension_loaded('gmp')) {
            $v = gmp_mul(gmp_init($id + 1), gmp_init(self::KNUTH));
            $v = gmp_mod($v, gmp_pow(2, 40)); // 取 40 位元 → 約 7 個 base62 字元
            return (int) gmp_strval($v);
        }
        // 後援：純整數版（足夠 demo）
        return (($id + 1) * 2654435761) % (1 << 31);
    }

    /** base62 編碼 */
    public function encode(int $n): string
    {
        if ($n === 0) {
            return '0';
        }
        $s = '';
        $base = strlen(self::ALPHABET);
        while ($n > 0) {
            $s = self::ALPHABET[$n % $base] . $s;
            $n = intdiv($n, $base);
        }
        return $s;
    }
}
