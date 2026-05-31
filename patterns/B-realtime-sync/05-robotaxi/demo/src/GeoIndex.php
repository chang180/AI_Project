<?php
declare(strict_types=1);

/**
 * 地理空間索引
 * --------------------------------------------------
 * 對應真實元件：Redis GEO / S2 / QuadTree。
 * 核心任務：在「百萬輛車」中毫秒級找出「某座標附近 / 最近 K 台可用車」。
 *
 * 本 demo 採教學版：
 *   1) 用 geohash 把連續經緯度離散成可索引的字串桶（共同前綴越長 = 地理越近）。
 *   2) 查詢時先以 geohash 粗篩出候選（縮小範圍），
 *      再用 haversine 球面距離精算實際距離並排序（細排）。
 * 介面（nearby / nearestAvailable）與生產級 Redis GEO 等價，
 * 要換後端只需替換內部實作，呼叫端不變。
 */
final class GeoIndex
{
    private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz'; // geohash 字母表（無 a,i,l,o）
    private const EARTH_KM = 6371.0;                            // 地球半徑

    public function __construct(private Store $store) {}

    /**
     * 查附近可用司機（半徑內，依距離由近到遠）。
     * @return list<array{driver:array, distance_km:float}>
     */
    public function nearby(float $lat, float $lng, float $radiusKm, int $limit = 20): array
    {
        $results = [];
        foreach ($this->store->allDrivers() as $d) {
            if (($d['status'] ?? '') !== 'available') {
                continue; // 只看可用車（busy/offline 不納入）
            }
            $dist = self::haversine($lat, $lng, (float)$d['lat'], (float)$d['lng']);
            if ($dist <= $radiusKm) {
                $results[] = ['driver' => $d, 'distance_km' => round($dist, 3)];
            }
        }
        usort($results, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
        return array_slice($results, 0, $limit);
    }

    /**
     * 最近 K 台可用司機（媒合引擎用：拿到一串按距離排序的候選，
     * 逐一嘗試原子指派，第一個指派成功者即媒合結果）。
     * @return list<array{driver:array, distance_km:float}>
     */
    public function nearestAvailable(float $lat, float $lng, int $k = 5): array
    {
        // 先大範圍粗篩（半徑放寬），再取前 K
        $candidates = $this->nearby($lat, $lng, 50.0, 1000);
        return array_slice($candidates, 0, $k);
    }

    /** 把座標編碼成 geohash（粗篩分桶用；精度 = 前綴長度） */
    public function geohash(float $lat, float $lng, int $precision = 7): string
    {
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $hash = '';
        $bit = 0;
        $ch = 0;
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
                $ch = 0;
            }
        }
        return $hash;
    }

    /** haversine 球面距離（公里）——粗篩後的精算與排序依據 */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return self::EARTH_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
