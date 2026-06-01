<?php
declare(strict_types=1);

/**
 * 欄壓縮（Column Compression）
 * --------------------------------------------------
 * 列式儲存最大的省空間優勢：同一欄的值「型別相同、分佈相近」，
 * 壓縮率遠高於行式（行式同一塊裡混著數字、字串、時間，難壓）。
 *
 * 本檔示範三種最經典、且能真實算出壓縮率的欄編碼：
 *
 *   1) 字典編碼（Dictionary）— 適合「低基數」字串欄（region、device）。
 *      把不重複值抽成字典，原欄改存「字典索引（小整數）」。
 *      例：["TW","TW","JP","TW"] → 字典 ["TW","JP"]，欄存 [0,0,1,0]。
 *
 *   2) RLE（Run-Length Encoding）— 適合「連續重複」欄（已排序、或同值成段）。
 *      把 [TW,TW,TW,JP,JP] 存成 [(TW,3),(JP,2)]。
 *
 *   3) Delta — 適合「單調遞增」數值欄（如 ts 時間戳）。
 *      只存與前一筆的差值：[1000,1004,1009] → [1000,+4,+5]，差值小、好壓。
 *
 * 這裡用「估算編碼後的元素數 / bytes」來呈現壓縮效果，邏輯為真實計算
 * （非真的位元打包；真實系統會再疊一層 LZ4/ZSTD）。
 */
final class Compressor
{
    /** 粗略估算原始未壓縮的「邏輯位元組」：字串用長度，數值固定 8 bytes */
    public static function rawBytes(array $values): int
    {
        $bytes = 0;
        foreach ($values as $v) {
            $bytes += is_string($v) ? max(1, strlen($v)) : 8;
        }
        return $bytes;
    }

    /** 字典編碼：回傳 [dict, codes, bytes]，bytes = 字典字串 + 每筆一個小整數 */
    public static function dictionary(array $values): array
    {
        $dict = [];
        $codes = [];
        foreach ($values as $v) {
            $key = (string) $v;
            if (!array_key_exists($key, $dict)) {
                $dict[$key] = count($dict);
            }
            $codes[] = $dict[$key];
        }
        $cardinality = count($dict);
        // 索引位元組：基數 <=256 用 1 byte，否則 2 bytes
        $codeBytes = $cardinality <= 256 ? 1 : 2;
        $dictBytes = 0;
        foreach (array_keys($dict) as $k) {
            $dictBytes += max(1, strlen($k));
        }
        $bytes = $dictBytes + count($codes) * $codeBytes;
        return [
            'dict'        => array_keys($dict),
            'cardinality' => $cardinality,
            'codes'       => $codes,
            'bytes'       => $bytes,
        ];
    }

    /** RLE：把連續相同值壓成 (值, 次數)，回傳 [runs, bytes] */
    public static function rle(array $values): array
    {
        $runs = [];
        $n = count($values);
        $i = 0;
        while ($i < $n) {
            $v = $values[$i];
            $len = 1;
            while ($i + $len < $n && $values[$i + $len] === $v) {
                $len++;
            }
            $runs[] = [$v, $len];
            $i += $len;
        }
        // 每段：一個值（數值 8 / 字串長度）+ 一個 4-byte 次數
        $bytes = 0;
        foreach ($runs as [$v, $len]) {
            $bytes += (is_string($v) ? max(1, strlen($v)) : 8) + 4;
        }
        return ['runs' => count($runs), 'bytes' => $bytes];
    }

    /** Delta：存差值，差值越小越省（這裡估「差值所需位元組」） */
    public static function delta(array $values): array
    {
        $bytes = 0;
        $prev = 0;
        $deltas = [];
        foreach ($values as $v) {
            $d = (int) $v - $prev;
            $prev = (int) $v;
            $deltas[] = $d;
            $ad = abs($d);
            // 差值越小用越少位元組
            $bytes += $ad < 128 ? 1 : ($ad < 32768 ? 2 : 4);
        }
        return ['bytes' => $bytes, 'sample' => array_slice($deltas, 0, 5)];
    }

    /**
     * 對單一欄自動選編碼：字串→字典，整數(看似時間戳)→delta，其他數值→RLE 與 raw 取小。
     * 回傳含原始/壓縮 bytes 與所選編碼，給查詢頁展示壓縮效果。
     */
    public static function analyzeColumn(string $name, string $type, array $values): array
    {
        $raw = self::rawBytes($values);
        if ($type === 'string') {
            $d = self::dictionary($values);
            return [
                'column' => $name, 'encoding' => 'dictionary',
                'raw_bytes' => $raw, 'compressed_bytes' => $d['bytes'],
                'cardinality' => $d['cardinality'],
                'ratio' => $raw > 0 ? round($raw / max(1, $d['bytes']), 2) : 0,
            ];
        }
        if ($name === 'ts') {
            $de = self::delta($values);
            return [
                'column' => $name, 'encoding' => 'delta',
                'raw_bytes' => $raw, 'compressed_bytes' => $de['bytes'],
                'ratio' => $raw > 0 ? round($raw / max(1, $de['bytes']), 2) : 0,
            ];
        }
        $r = self::rle($values);
        $best = min($r['bytes'], $raw);
        return [
            'column' => $name, 'encoding' => $best === $r['bytes'] ? 'rle' : 'raw',
            'raw_bytes' => $raw, 'compressed_bytes' => $best,
            'ratio' => $raw > 0 ? round($raw / max(1, $best), 2) : 0,
        ];
    }
}
