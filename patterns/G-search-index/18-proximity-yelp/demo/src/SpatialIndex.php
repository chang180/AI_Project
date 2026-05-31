<?php
declare(strict_types=1);

/**
 * 空間索引（Spatial Index）
 * --------------------------------------------------
 * 對應真實元件：Geohash 前綴索引 / PostGIS GiST / Google S2 / Redis GEO。
 * 核心任務：在「百萬個靜態地點」中，毫秒級找出「某座標附近 / 最近 K 個」。
 *
 * 設計：以 geohash 前綴把地點分桶（同桶 = 地理鄰近）。
 *   - 半徑查詢：
 *       1) 算出查詢點的 geohash + 周圍 8 個鄰格 → 共 9 桶（解決邊界遺漏）。
 *       2) 只掃這 9 桶的候選（粗篩，大幅縮小範圍），
 *       3) 用 haversine 球面距離精算 → 過濾掉半徑外者 → 依距離排序（細排）。
 *   - 最近 K 個：同上取候選後取前 K。
 *   - 可疊加屬性過濾（類別、最低評分）與分頁。
 *
 * 把介面（nearby / nearestK）固定住，要換成生產級 PostGIS / S2 只需替換內部實作。
 */
final class SpatialIndex
{
    private const EARTH_KM = 6371.0;

    /** @var array<string, list<string>> geohash 桶 → 地點 id 清單 */
    private array $buckets = [];

    /** 索引精度（前綴長度）：6 ≈ 約 1.2km × 0.6km 的格子，適合市區半徑查詢 */
    private int $precision;

    public function __construct(
        private GeoHash $geo,
        private PlaceStore $store,
        int $precision = 6
    ) {
        $this->precision = $precision;
    }

    /** 把單一地點放進對應 geohash 桶（寫路徑 / 索引更新） */
    public function index(array $place): void
    {
        $bucket = $this->geo->encode((float)$place['lat'], (float)$place['lng'], $this->precision);
        $this->buckets[$bucket][] = $place['id'];
    }

    /** 重建整個索引（把 PlaceStore 內所有地點掃進桶） */
    public function rebuild(): void
    {
        $this->buckets = [];
        foreach ($this->store->all() as $place) {
            $this->index($place);
        }
    }

    /**
     * 半徑查詢：回傳 radiusKm 內、依距離由近到遠排序的地點。
     * 可選 category 過濾、minRating 過濾，並支援 offset/limit 分頁。
     *
     * @return array{
     *   results: list<array{place:array, distance_km:float}>,
     *   buckets_scanned: list<string>,
     *   candidates: int,
     *   total_matched: int
     * }
     */
    public function nearby(
        float $lat,
        float $lng,
        float $radiusKm,
        ?string $category = null,
        float $minRating = 0.0,
        int $offset = 0,
        int $limit = 20
    ): array {
        $center  = $this->geo->encode($lat, $lng, $this->precision);
        $cells   = $this->geo->neighbors($center); // 中心格 + 8 鄰格
        $matched = [];
        $candidateCount = 0;

        foreach ($cells as $cell) {
            foreach ($this->buckets[$cell] ?? [] as $id) {
                $candidateCount++;
                $place = $this->store->get($id);
                if ($place === null) {
                    continue;
                }
                // 屬性過濾（評分 / 類別）
                if ($category !== null && $category !== '' && $place['category'] !== $category) {
                    continue;
                }
                if ((float)$place['rating'] < $minRating) {
                    continue;
                }
                // haversine 精算，過濾半徑外者
                $dist = self::haversine($lat, $lng, (float)$place['lat'], (float)$place['lng']);
                if ($dist <= $radiusKm) {
                    $matched[] = ['place' => $place, 'distance_km' => round($dist, 3)];
                }
            }
        }

        usort($matched, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
        $total = count($matched);
        $page  = array_slice($matched, $offset, $limit);

        return [
            'results'         => $page,
            'buckets_scanned' => $cells,
            'candidates'      => $candidateCount,
            'total_matched'   => $total,
        ];
    }

    /**
     * 最近 K 個地點（不限定半徑，但仍以鄰格粗篩；若候選不足會逐步放大精度）。
     * @return array{
     *   results: list<array{place:array, distance_km:float}>,
     *   buckets_scanned: list<string>,
     *   precision_used: int
     * }
     */
    public function nearestK(float $lat, float $lng, int $k = 5, ?string $category = null): array
    {
        // 從索引精度起，若候選不足 K，逐步縮短前綴（放大格子）擴大搜尋範圍
        for ($p = $this->precision; $p >= 1; $p--) {
            $center = $this->geo->encode($lat, $lng, $p);
            $cells  = $this->geo->neighbors($center);
            $matched = [];

            foreach ($cells as $cell) {
                // 桶是用 $this->precision 建的；放大搜尋時用前綴比對命中較粗的範圍
                foreach ($this->bucketsByPrefix($cell, $p) as $id) {
                    $place = $this->store->get($id);
                    if ($place === null) {
                        continue;
                    }
                    if ($category !== null && $category !== '' && $place['category'] !== $category) {
                        continue;
                    }
                    $dist = self::haversine($lat, $lng, (float)$place['lat'], (float)$place['lng']);
                    $matched[] = ['place' => $place, 'distance_km' => round($dist, 3)];
                }
            }

            usort($matched, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
            if (count($matched) >= $k || $p === 1) {
                return [
                    'results'         => array_slice($matched, 0, $k),
                    'buckets_scanned' => $cells,
                    'precision_used'  => $p,
                ];
            }
        }
        return ['results' => [], 'buckets_scanned' => [], 'precision_used' => 0];
    }

    /** 列出目前所有桶與其地點數（教學展示用） */
    public function bucketSummary(): array
    {
        $out = [];
        foreach ($this->buckets as $hash => $ids) {
            $out[$hash] = count($ids);
        }
        ksort($out);
        return $out;
    }

    /**
     * 以前綴比對找出落在「較粗格子」內的所有地點 id。
     * 桶鍵長度固定為 $this->precision；當以較短前綴 $len 搜尋時，
     * 凡桶鍵以該前綴開頭者皆命中。
     * @return list<string>
     */
    private function bucketsByPrefix(string $prefix, int $len): array
    {
        if ($len >= $this->precision) {
            return $this->buckets[$prefix] ?? [];
        }
        $short = substr($prefix, 0, $len);
        $ids   = [];
        foreach ($this->buckets as $hash => $bucketIds) {
            if (substr($hash, 0, $len) === $short) {
                foreach ($bucketIds as $id) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
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
