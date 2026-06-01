<?php
declare(strict_types=1);

/**
 * 派單引擎 + 訂單狀態機（外送平台核心）
 * --------------------------------------------------
 * 比叫車（RoboTaxi ⑤）多了「餐廳備餐」這一環，所以是三邊媒合 + 兩段距離：
 *   - 訂單生命週期（狀態機）：placed → accepted → preparing → picked_up → delivered
 *   - 派單（地理媒合）：用 GeoIndex 在「餐廳附近的可用外送員」中挑最近的，
 *     用 CAS 原子指派（available → assigned），失敗即試下一位候選（防同一張單被搶兩次）。
 *   - ETA：餐廳備餐時間 + 外送員到餐廳 + 餐廳到食客，兩段直線距離 ÷ 平均車速的教學近似。
 *
 * 訂單狀態機（合法轉移；亂序 / 重複推進會被 CAS 擋下）：
 *   placed     下單（等餐廳接受）
 *   accepted   餐廳接受（可派外送員）
 *   preparing  備餐中（外送員可前往）
 *   picked_up  外送員取餐（前往食客）
 *   delivered  送達（外送員釋放回 available）
 */
final class Dispatcher
{
    private const AVG_SPEED_KMH = 25.0; // 市區外送平均車速，用來把距離換算成 ETA
    private const PREP_MIN      = 12.0; // 餐廳平均備餐時間（分）

    /** 狀態機：目前狀態 → 下一個合法狀態 */
    private const NEXT = [
        'placed'    => 'accepted',
        'accepted'  => 'preparing',
        'preparing' => 'picked_up',
        'picked_up' => 'delivered',
    ];

    public function __construct(
        private Store $store,
        private GeoIndex $geo,
    ) {}

    /**
     * 食客下單。建立訂單（狀態 placed），記下餐廳與送達座標。
     */
    public function placeOrder(
        string $eaterId,
        string $restaurant,
        float $rLat,
        float $rLng,
        float $dLat,
        float $dLng
    ): array {
        $orderId = 'o_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $order = [
            'order_id'    => $orderId,
            'eater_id'    => $eaterId,
            'restaurant'  => $restaurant,
            'rest_lat'    => $rLat,
            'rest_lng'    => $rLng,
            'dest_lat'    => $dLat,
            'dest_lng'    => $dLng,
            'status'      => 'placed',
            'courier_id'  => null,
            'distance_km' => round(GeoIndex::haversine($rLat, $rLng, $dLat, $dLng), 3), // 餐廳→食客
            'eta_min'     => null,
            'created_at'  => gmdate('c'),
            'updated_at'  => gmdate('c'),
            'history'     => [['status' => 'placed', 'at' => gmdate('c')]],
        ];
        $this->store->putOrder($orderId, $order);
        return $order;
    }

    /**
     * 派單（地理媒合 + 原子指派）。
     * 在餐廳座標附近找最近 K 個可用外送員，逐一嘗試 CAS：
     *   available → assigned（綁定此訂單）。第一個搶成功者即被指派。
     * 同時把外送員寫進訂單（courier_id）並算 ETA。
     * 規則：訂單須為 accepted 或 preparing 才可派（餐廳已接單）。
     * @return array{order:array,courier:array,distance_km:float}|null 無可用外送員回 null
     */
    public function dispatch(string $orderId, int $k = 5): ?array
    {
        $order = $this->store->getOrder($orderId);
        if (!$order || !in_array($order['status'], ['accepted', 'preparing'], true) || $order['courier_id'] !== null) {
            return null; // 尚未接單 / 已派過
        }

        // 在「餐廳」附近找最近可用外送員（先接到餐廳取餐）
        $candidates = $this->geo->nearestAvailable((float)$order['rest_lat'], (float)$order['rest_lng'], $k);

        foreach ($candidates as $c) {
            $courierId = $c['courier']['courier_id'];
            // 原子指派：只有 status 仍為 available 才搶得到 → 防同一外送員同時被派兩單
            if (!$this->store->compareAndSetStatus($courierId, 'available', 'assigned', $orderId)) {
                continue; // 已被其他並發派單搶走 → 試下一位候選
            }

            $toRestaurant = $c['distance_km'];                  // 外送員 → 餐廳
            $toEater      = (float)$order['distance_km'];        // 餐廳 → 食客
            $etaMin = round(
                self::PREP_MIN
                + ($toRestaurant / self::AVG_SPEED_KMH * 60)
                + ($toEater / self::AVG_SPEED_KMH * 60),
                1
            );

            // 把外送員寫進訂單（不改訂單狀態，狀態由 advance 推進）
            $updated = $this->store->getOrder($orderId);
            $updated['courier_id']     = $courierId;
            $updated['courier_name']   = $c['courier']['name'];
            $updated['eta_min']        = $etaMin;
            $updated['to_rest_km']     = $toRestaurant;
            $updated['dispatched_at']  = gmdate('c');
            $updated['updated_at']     = gmdate('c');
            $this->store->putOrder($orderId, $updated);

            return ['order' => $updated, 'courier' => $c['courier'], 'distance_km' => $toRestaurant];
        }

        return null; // 候選都被搶光 / 附近無可用外送員
    }

    /**
     * 推進訂單狀態（狀態機）。
     * 用 CAS 從目前合法狀態推進到下一狀態，亂序 / 重複會被擋下。
     * 送達（→ delivered）時把外送員釋放回 available。
     * @return array{ok:bool,order:?array,error:?string}
     */
    public function advance(string $orderId): array
    {
        $order = $this->store->getOrder($orderId);
        if (!$order) {
            return ['ok' => false, 'order' => null, 'error' => '訂單不存在'];
        }
        $cur = $order['status'];
        if (!isset(self::NEXT[$cur])) {
            return ['ok' => false, 'order' => $order, 'error' => "狀態 $cur 已是終態，無法再推進"];
        }
        $next = self::NEXT[$cur];

        // preparing → picked_up 之前必須已派外送員
        if ($cur === 'preparing' && ($order['courier_id'] ?? null) === null) {
            return ['ok' => false, 'order' => $order, 'error' => '尚未派外送員，無法取餐，請先派單'];
        }

        $updated = $this->store->advanceOrder($orderId, $cur, $next);
        if ($updated === null) {
            return ['ok' => false, 'order' => $order, 'error' => '狀態已被並發更新，請重試'];
        }

        // 送達：釋放外送員回 available（狀態機收尾）
        if ($next === 'delivered' && !empty($updated['courier_id'])) {
            $this->store->compareAndSetStatus($updated['courier_id'], 'assigned', 'available');
        }

        return ['ok' => true, 'order' => $updated, 'error' => null];
    }

    /**
     * 訂單追蹤：回傳訂單狀態、外送員當前位置、即時 ETA。
     * 外送員移動後（updateLocation）ETA 會隨之變化（位置串流 → 動態 ETA）。
     */
    public function track(string $orderId): ?array
    {
        $order = $this->store->getOrder($orderId);
        if (!$order) {
            return null;
        }
        $courier = null;
        $liveEta = $order['eta_min'] ?? null;
        if (!empty($order['courier_id'])) {
            $courier = $this->store->getCourier($order['courier_id']);
            if ($courier) {
                // 依「目前進度」算即時 ETA：取餐前算到餐廳的剩餘；取餐後算到食客的剩餘
                if (in_array($order['status'], ['accepted', 'preparing'], true)) {
                    $rest = GeoIndex::haversine(
                        (float)$courier['lat'], (float)$courier['lng'],
                        (float)$order['rest_lat'], (float)$order['rest_lng']
                    );
                    $toEater = (float)$order['distance_km'];
                    $liveEta = round(self::PREP_MIN + ($rest + $toEater) / self::AVG_SPEED_KMH * 60, 1);
                } elseif ($order['status'] === 'picked_up') {
                    $rest = GeoIndex::haversine(
                        (float)$courier['lat'], (float)$courier['lng'],
                        (float)$order['dest_lat'], (float)$order['dest_lng']
                    );
                    $liveEta = round($rest / self::AVG_SPEED_KMH * 60, 1);
                } elseif ($order['status'] === 'delivered') {
                    $liveEta = 0.0;
                }
            }
        }
        return [
            'order'    => $order,
            'courier'  => $courier,
            'live_eta_min' => $liveEta,
            'next_status'  => self::NEXT[$order['status']] ?? null,
        ];
    }
}
