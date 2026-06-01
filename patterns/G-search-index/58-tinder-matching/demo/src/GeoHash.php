<?php
declare(strict_types=1);

/**
 * GeoHash —— 經緯度 ↔ base32 字串編碼器（與「附近的人 ⑱」共用的地理候選基礎）
 * --------------------------------------------------
 * 對應真實元件：交友配對的「候選召回」第一步 —— 用 geohash 找地理就近的人。
 *
 * 核心觀念：
 *   1) 把連續二維座標 (lat,lng) 交錯二分編成一維字串；共同前綴越長 = 越接近。
 *   2) 邊界問題：相鄰兩點可能落在不同格子，故「找附近」要掃中心格 + 8 鄰格共 9 桶。
 *
 * 注意：本機 PHP 未載入 mbstring，全程只用 strlen / substr（geohash 皆 ASCII）。
 */
final class GeoHash
{
    private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    public function encode(float $lat, float $lng, int $precision = 6): string
    {
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $hash = '';
        $bit  = 0;
        $ch   = 0;
        $even = true;

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

    /** @return array{lat:float, lng:float, lat_err:float, lng_err:float} */
    public function decode(string $hash): array
    {
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $even = true;
        $len  = strlen($hash);

        for ($i = 0; $i < $len; $i++) {
            $cd = strpos(self::BASE32, $hash[$i]);
            if ($cd === false) {
                continue;
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
     * 中心格 + 周圍 8 鄰格共 9 個 geohash（去重）。解決邊界遺漏：
     * 貼著格邊的人前綴會落在隔壁格，只查中心格會漏掉他。
     * @return list<string>
     */
    public function neighbors(string $hash): array
    {
        $box = $this->decode($hash);
        $precision = strlen($hash);
        $latStep = $box['lat_err'] * 2;
        $lngStep = $box['lng_err'] * 2;

        $cells = [$hash => true];
        foreach ([-1, 0, 1] as $dLat) {
            foreach ([-1, 0, 1] as $dLng) {
                if ($dLat === 0 && $dLng === 0) {
                    continue;
                }
                $nLat = $box['lat'] + $dLat * $latStep;
                $nLng = $box['lng'] + $dLng * $lngStep;
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

    /** haversine 球面距離（公里）—— 偏好「最大距離」過濾與排序用 */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
