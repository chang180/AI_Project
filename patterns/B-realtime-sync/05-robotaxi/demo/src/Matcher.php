<?php
declare(strict_types=1);

/**
 * 媒合引擎
 * --------------------------------------------------
 * 叫車流程（即時請求型，RoboTaxi 的關鍵路徑）：
 *   1) 用 GeoIndex 取得「最近 K 台可用車」候選（已按距離排序）。
 *   2) 逐一嘗試「原子指派」：對候選車做條件更新 available → matched（CAS）。
 *      - 成功（受影響 1 列）→ 這台歸我，建立行程，回傳。
 *      - 失敗（0 列，被別的請求搶走）→ 跳到下一台候選（防並發重複指派）。
 *   3) 候選都搶不到 → 視為附近無車，回 null（生產會擴大半徑或排隊）。
 *
 * ETA 為教學近似：直線距離 ÷ 平均車速（生產走路網路由引擎）。
 */
final class Matcher
{
    private const AVG_SPEED_KMH = 30.0; // 市區平均車速，用來把距離換算成 ETA

    public function __construct(
        private Store $store,
        private GeoIndex $geo,
    ) {}

    /**
     * 媒合一筆叫車請求。
     * @return array|null 成功回傳行程，附近無可用車回 null
     */
    public function requestRide(string $riderId, float $lat, float $lng, int $k = 5): ?array
    {
        $candidates = $this->geo->nearestAvailable($lat, $lng, $k);

        foreach ($candidates as $c) {
            $driverId = $c['driver']['driver_id'];
            // 原子指派：只有 status 仍為 available 才搶得到，避免兩個請求搶同一台
            if (!$this->store->compareAndSetStatus($driverId, 'available', 'matched')) {
                continue; // 已被其他並發請求指派 → 試下一台候選
            }

            $distance = $c['distance_km'];
            $etaMin = round($distance / self::AVG_SPEED_KMH * 60, 1);
            $rideId = 'r_' . substr(bin2hex(random_bytes(4)), 0, 8);
            $ride = [
                'ride_id'     => $rideId,
                'rider_id'    => $riderId,
                'driver_id'   => $driverId,
                'plate'       => $c['driver']['plate'],
                'pickup'      => ['lat' => $lat, 'lng' => $lng],
                'distance_km' => $distance,
                'eta_min'     => $etaMin,
                'surge'       => $this->surge($lat, $lng),
                'status'      => 'matched',
                'matched_at'  => gmdate('c'),
            ];
            $this->store->putRide($rideId, $ride);
            return $ride;
        }

        return null; // 候選都被搶光 / 附近無可用車
    }

    /**
     * 完成行程：行程標記 completed，並把車輛釋放回 available（狀態機收尾）。
     */
    public function completeRide(string $rideId): bool
    {
        $ride = $this->store->getRide($rideId);
        if (!$ride || $ride['status'] === 'completed') {
            return false;
        }
        $this->store->setRideStatus($rideId, 'completed');
        // 釋放車：on_trip/matched → available（這裡不強制 CAS，因只有此行程持有該車）
        $this->store->compareAndSetStatus($ride['driver_id'], 'matched', 'available')
            || $this->store->compareAndSetStatus($ride['driver_id'], 'on_trip', 'available');
        return true;
    }

    /**
     * 動態加價（surge）教學示意：以叫車點周邊小半徑內的可用車數估供給，
     * 車越少係數越高。生產會用「該格叫車數 / 可用車數」即時計算。
     */
    private function surge(float $lat, float $lng): float
    {
        $supply = count($this->geo->nearby($lat, $lng, 2.0, 50));
        return match (true) {
            $supply >= 3 => 1.0,
            $supply === 2 => 1.3,
            $supply === 1 => 1.6,
            default       => 2.0,
        };
    }
}
