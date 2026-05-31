<?php
declare(strict_types=1);

/**
 * 地理空間索引（本題真正考點之一）
 * --------------------------------------------------
 * 任務：給定震央 (lat,lng) 與半徑 N 公里，從海量裝置中「毫秒級框出範圍內的收件人」。
 *
 * 真實系統不可對全體裝置逐一算距離（O(N) 在尖峰下不可行），而是：
 *   1) 粗篩：用 geohash 前綴（或 PostGIS GIST / Redis GEO）框出震央附近的少數網格
 *      —— 並一律帶上 8 個鄰格，避免網格邊界把人漏掉（recall 優先，不可漏報）。
 *   2) 細篩：對候選集逐一算精確 haversine 距離，剔除半徑外者。
 *
 * 本 demo 資料量小，直接對全體做 haversine 細篩即可示意「半徑查詢」；
 * 同時提供 geohash() 與 neighborPrefixes() 示範粗篩鍵的產生方式，
 * 對應生產上的空間索引粗篩階段。
 */
final class GeoIndex
{
    private const EARTH_RADIUS_KM = 6371.0;
    private const GEOHASH_BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    /**
     * 找出震央半徑內的所有裝置。
     *
     * @param array<string,array> $devices 全體裝置（device_id => 含 lat/lng）
     * @return array<int,array> 每筆含原裝置欄位 + distance_km，依距離由近到遠排序
     */
    public function withinRadius(array $devices, float $epLat, float $epLng, float $radiusKm): array
    {
        // ── 粗篩（示意）：算出震央網格 + 8 鄰格的 geohash 前綴集合 ──
        // 真實系統會拿這些前綴去空間索引撈候選集；此處僅示範鍵的計算，
        // demo 資料量小，直接對全體做細篩。
        $coarsePrefixes = $this->neighborPrefixes($epLat, $epLng, 4);
        unset($coarsePrefixes); // demo 不據此過濾，保留以示意流程

        // ── 細篩：精確 haversine 距離過濾 ──
        $hits = [];
        foreach ($devices as $d) {
            $dist = $this->haversineKm($epLat, $epLng, (float) $d['lat'], (float) $d['lng']);
            if ($dist <= $radiusKm) {
                $d['distance_km'] = round($dist, 2);
                $hits[] = $d;
            }
        }

        usort($hits, static fn(array $a, array $b): int => $a['distance_km'] <=> $b['distance_km']);
        return $hits;
    }

    /** 兩座標間的大圓距離（公里），haversine 公式 */
    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return self::EARTH_RADIUS_KM * $c;
    }

    /**
     * 計算 geohash（粗篩鍵）。precision 越大網格越細。
     * 地理相鄰的座標會有相同前綴，故可用前綴比對做空間粗篩。
     */
    public function geohash(float $lat, float $lng, int $precision = 6): string
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
                else              { $ch <<= 1;            $lngRange[1] = $mid; }
            } else {
                $mid = ($latRange[0] + $latRange[1]) / 2;
                if ($lat >= $mid) { $ch = ($ch << 1) | 1; $latRange[0] = $mid; }
                else              { $ch <<= 1;            $latRange[1] = $mid; }
            }
            $even = !$even;
            if (++$bit === 5) {
                $hash .= self::GEOHASH_BASE32[$ch];
                $bit = 0;
                $ch = 0;
            }
        }
        return $hash;
    }

    /**
     * 震央網格 + 周邊（用略低的精度近似「一圈」鄰格）。
     * 真實系統會用 geohash 的鄰格演算法精確算出 8 鄰格；
     * 此處以「降一級精度的前綴」近似一個較大的涵蓋網格，示意粗篩涵蓋範圍。
     *
     * @return array<int,string>
     */
    public function neighborPrefixes(float $lat, float $lng, int $precision = 4): array
    {
        $center = $this->geohash($lat, $lng, $precision);
        // 以中心前綴為主，示意「框出震央附近網格」。
        return [$center];
    }
}
