<?php
declare(strict_types=1);

/**
 * 抹除碼（Erasure Coding）— 教學簡化版
 * ---------------------------------------------------------------
 * 真實系統（如 Reed-Solomon）能容忍任意 m 個分片遺失，計算用有限體(GF) 多項式。
 * 這裡用最簡單可理解的 k=2 資料分片 + m=1 XOR 同位（parity）：
 *
 *     parity = d0 XOR d1
 *
 * 性質（可實際驗證）：
 *   - 任一資料分片遺失 → 用另一資料分片 XOR parity 還原：
 *         d0 = d1 XOR parity     d1 = d0 XOR parity
 *   - parity 分片遺失 → 資料本身還在，重算 parity 即可。
 *   - 因此 k=2,m=1 可「容忍任一分片遺失」。
 *
 * 空間取捨（本題核心考點）：
 *   - 副本 R=3：存 3 份完整資料 → 3.0 倍空間，容忍 2 份遺失。
 *   - 抹除碼 k=2,m=1：存 2 資料 + 1 parity → 1.5 倍空間，容忍 1 份遺失。
 *   - 一般化：抹除碼空間倍率 = (k+m)/k；副本空間倍率 = R。
 *     抹除碼用更少空間換到相近的可靠度，代價是還原要算（CPU）。
 *
 * 為對齊 XOR，分片以「等長 byte 區塊」處理，最後一塊用 0x00 補齊（padding），
 * 還原後再依原始長度截斷。
 */
final class Erasure
{
    public const K = 2; // 資料分片數
    public const M = 1; // parity 分片數

    /**
     * 把一段位元組編碼成 k 個資料分片 + m 個 parity 分片。
     *
     * @return array{shards: list<string>, size: int, shardLen: int}
     *   shards[0..k-1] = 資料分片；shards[k] = parity
     */
    public static function encode(string $data): array
    {
        $size = strlen($data);
        // 每個資料分片長度（向上取整），不足補 0x00
        $shardLen = (int) ceil(max($size, self::K) / self::K);
        $padded = str_pad($data, $shardLen * self::K, "\0");

        $shards = [];
        for ($i = 0; $i < self::K; $i++) {
            $shards[$i] = substr($padded, $i * $shardLen, $shardLen);
        }
        // parity = d0 XOR d1 XOR ...（這裡 K=2 即 d0 XOR d1）
        $parity = str_repeat("\0", $shardLen);
        foreach (range(0, self::K - 1) as $i) {
            $parity = self::xorStr($parity, $shards[$i]);
        }
        $shards[self::K] = $parity;

        return ['shards' => $shards, 'size' => $size, 'shardLen' => $shardLen];
    }

    /**
     * 在最多遺失 m(=1) 個分片時還原原始資料。
     *
     * @param array<int,?string> $shards 索引 0..k+m-1；遺失者為 null
     * @param int $size 原始位元組長度（用來去除 padding）
     */
    public static function decode(array $shards, int $size): string
    {
        $total = self::K + self::M;
        $missing = [];
        for ($i = 0; $i < $total; $i++) {
            if (!isset($shards[$i]) || $shards[$i] === null) {
                $missing[] = $i;
            }
        }
        if (count($missing) > self::M) {
            throw new RuntimeException('遺失分片數 ' . count($missing) . ' 超過可容忍上限 ' . self::M . '，無法還原');
        }

        if ($missing === []) {
            // 全在，直接拼資料分片
            return self::join($shards, $size);
        }

        $lost = $missing[0];
        // 還原規則：缺哪一片，就用其餘所有片 XOR 起來補回（XOR 自反特性）
        $shardLen = self::firstLen($shards);
        $recovered = str_repeat("\0", $shardLen);
        for ($i = 0; $i < $total; $i++) {
            if ($i === $lost) {
                continue;
            }
            $recovered = self::xorStr($recovered, (string) $shards[$i]);
        }
        $shards[$lost] = $recovered;

        return self::join($shards, $size);
    }

    /** 只拼資料分片（0..k-1），再依原始長度截斷掉 padding。 */
    private static function join(array $shards, int $size): string
    {
        $data = '';
        for ($i = 0; $i < self::K; $i++) {
            $data .= (string) $shards[$i];
        }
        return substr($data, 0, $size);
    }

    private static function firstLen(array $shards): int
    {
        foreach ($shards as $s) {
            if ($s !== null) {
                return strlen((string) $s);
            }
        }
        return 0;
    }

    /** 等長位元組 XOR。 */
    private static function xorStr(string $a, string $b): string
    {
        $len = strlen($a);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($a[$i]) ^ ord($b[$i]));
        }
        return $out;
    }
}
