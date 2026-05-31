<?php
declare(strict_types=1);

/**
 * GeoHash —— 經緯度 ↔ base32 字串編碼器
 * --------------------------------------------------
 * 對應真實元件：空間索引的「分桶鍵」產生器（Geohash / Google S2 cell id）。
 *
 * 核心觀念：
 *   1) 把連續的二維座標（lat, lng）交錯二分，編成一維字串。
 *      → 共同前綴越長，地理位置越接近（局部性 / locality）。
 *   2) 前綴長度 = 精度：每多一字元，格子面積縮小約 1/32。
 *   3) 邊界問題：相鄰兩點可能落在不同格子（前綴不同），
 *      因此「半徑查詢」必須同時掃描中心格 + 周圍 8 個鄰格（見 neighbors）。
 *
 * 注意：本機 PHP 未載入 mbstring，全程只用 strlen / substr（geohash 皆 ASCII）。
 */
final class GeoHash
{
    /** geohash 字母表（刻意排除 a, i, l, o 以減少人眼混淆） */
    private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    /**
     * 把座標編碼成 geohash 字串。
     * @param int $precision 前綴長度（字元數）；越長格子越小、越精準
     */
    public function encode(float $lat, float $lng, int $precision = 6): string
    {
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $hash = '';
        $bit  = 0;   // 目前 5-bit 字元已填入的位數
        $ch   = 0;   // 目前累積的 5-bit 值
        $even = true; // true=這一位切經度(lng)，false=切緯度(lat)，交錯進行

        while (strlen($hash) < $precision) {
            if ($even) {
                $mid = ($lngRange[0] + $lngRange[1]) / 2;
                if ($lng >= $mid) { $ch = ($ch << 1) | 1; $lngRange[0] = $mid; }
                else              { $ch = ($ch << 1);     $lngRange[1] = $mid; }
            } else {
                $mid = ($latRange[0] + $latRange[1]) / 2;
                if ($lat >= $mid) { $ch = ($ch << 1) | 1; $latRange[0] = $mid; }
                else              { $ch = ($ch << 1);     $latRange[1] = $mid; }
            }
            $even = !$even;
            if (++$bit === 5) {
                $hash .= self::BASE32[$ch];
                $bit = 0;
                $ch  = 0;
            }
        }
        return $hash;
    }

    /**
     * 解碼 geohash → 該格子的中心座標與經緯度範圍。
     * @return array{lat:float, lng:float, lat_err:float, lng_err:float}
     */
    public function decode(string $hash): array
    {
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $even = true;
        $len  = strlen($hash);

        for ($i = 0; $i < $len; $i++) {
            $cd = strpos(self::BASE32, $hash[$i]);
            if ($cd === false) {
                continue; // 非法字元忽略
            }
            for ($mask = 16; $mask >= 1; $mask >>= 1) {
                $bit = ($cd & $mask) ? 1 : 0;
                if ($even) {
                    $mid = ($lngRange[0] + $lngRange[1]) / 2;
                    if ($bit) { $lngRange[0] = $mid; } else { $lngRange[1] = $mid; }
                } else {
                    $mid = ($latRange[0] + $latRange[1]) / 2;
                    if ($bit) { $latRange[0] = $mid; } else { $latRange[1] = $mid; }
                }
                $even = !$even;
            }
        }
        return [
            'lat'     => ($latRange[0] + $latRange[1]) / 2,
            'lng'     => ($lngRange[0] + $lngRange[1]) / 2,
            'lat_err' => ($latRange[1] - $latRange[0]) / 2,
            'lng_err' => ($lngRange[1] - $lngRange[0]) / 2,
        ];
    }

    /**
     * 回傳「中心格 + 周圍 8 個鄰格」共 9 個 geohash（去重、去非法）。
     * 這是解決 geohash 邊界遺漏的關鍵：靠近格子邊緣的鄰近地點，
     * 其 geohash 前綴會落在隔壁格，只查中心格會漏掉它們。
     *
     * 作法：用該格的中心座標 + 該精度下的格子寬高，往八方各位移一格再重新編碼。
     * @return list<string> 至多 9 個（極端緯度近極點時可能有重複，已去重）
     */
    public function neighbors(string $hash): array
    {
        $box = $this->decode($hash);
        $precision = strlen($hash);
        // 一格的經緯度跨度 = 誤差 ×2
        $latStep = $box['lat_err'] * 2;
        $lngStep = $box['lng_err'] * 2;

        $cells = [$hash => true]; // 用關聯陣列去重
        foreach ([-1, 0, 1] as $dLat) {
            foreach ([-1, 0, 1] as $dLng) {
                if ($dLat === 0 && $dLng === 0) {
                    continue;
                }
                $nLat = $box['lat'] + $dLat * $latStep;
                $nLng = $box['lng'] + $dLng * $lngStep;
                // 緯度夾限、經度環繞（換日線）
                if ($nLat > 90.0 || $nLat < -90.0) {
                    continue;
                }
                if ($nLng > 180.0)  { $nLng -= 360.0; }
                if ($nLng < -180.0) { $nLng += 360.0; }
                $cells[$this->encode($nLat, $nLng, $precision)] = true;
            }
        }
        return array_keys($cells);
    }
}
