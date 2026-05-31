<?php
declare(strict_types=1);

/**
 * 預訂服務（本題核心：並發防超賣 + 區間原子扣減 + hold/TTL）
 * --------------------------------------------------
 * 對應真實系統的「預訂服務」：在 DB 交易內鎖住日期區間每一格，
 * 全部可用才原子扣減；hold 帶 TTL，付款在交易外，成功再 confirm。
 *
 * 狀態機（每格 room_inventory）：OPEN → HELD →(confirm)→ BOOKED
 *                                          └→(逾時/取消)→ OPEN
 */
final class BookingService
{
    /** 保留 TTL（秒）；demo 用很短的值方便觀察逾時釋放 */
    public const HOLD_TTL_SECONDS = 600;

    public function __construct(private InventoryStore $store) {}

    /** 建立房源 + 一段日期的庫存日曆（每天一格 OPEN） */
    public function createRoom(string $roomId, string $name, string $from, string $to): array
    {
        $dates = $this->nightsBetween($from, $to, true); // 含 from..to 全部天數（建庫存用）
        return $this->store->transaction(function (array &$data) use ($roomId, $name, $dates) {
            if (isset($data['rooms'][$roomId])) {
                return [['error' => '房源已存在'], false];
            }
            $calendar = [];
            foreach ($dates as $d) {
                $calendar[$d] = ['status' => 'OPEN', 'hold_id' => null, 'version' => 0];
            }
            $data['rooms'][$roomId] = ['name' => $name, 'calendar' => $calendar];
            return [['room_id' => $roomId, 'days' => count($calendar)], true];
        });
    }

    /**
     * 建立保留（hold）：防超賣核心。
     * 在「交易（檔案鎖）」內：檢查區間每一晚皆 OPEN 才原子扣減為 HELD。
     * 任一晚不可用 → 整筆拒絕（回 409 語意），不做任何扣減。
     *
     * @param string $strategy 'pessimistic'（鎖內逐格檢查再更新）或 'optimistic'（條件更新 + affected 計數）
     */
    public function hold(string $roomId, string $checkin, string $checkout, string $strategy = 'pessimistic'): array
    {
        $nights = $this->nightsBetween($checkin, $checkout, false); // 退房日不佔
        if ($nights === []) {
            return ['ok' => false, 'code' => 422, 'error' => '日期區間無效（退房需晚於入住）'];
        }

        return $this->store->transaction(function (array &$data) use ($roomId, $nights, $strategy, $checkin, $checkout) {
            $room = $data['rooms'][$roomId] ?? null;
            if ($room === null) {
                return [['ok' => false, 'code' => 404, 'error' => '房源不存在'], false];
            }
            $cal = $room['calendar'];

            // 1) 先做惰性 TTL：把已逾期的 HELD 釋放成 OPEN（避免被過期保留卡住）
            $this->expireStaleHolds($data);
            $cal = $data['rooms'][$roomId]['calendar']; // 重新取（可能被釋放更新）

            // 2) 區間可用性檢查（每一晚都必須 OPEN）
            $unavailable = [];
            foreach ($nights as $d) {
                if (!isset($cal[$d])) {
                    $unavailable[] = $d . '(無庫存)';
                } elseif ($cal[$d]['status'] !== 'OPEN') {
                    $unavailable[] = $d . '(' . $cal[$d]['status'] . ')';
                }
            }

            if ($strategy === 'optimistic') {
                // 樂觀鎖示意：條件更新「status==OPEN」的格，最後比對 affected==天數。
                // 在真實 DB 是 UPDATE ... WHERE status='OPEN'；這裡在鎖內模擬同樣語意。
                $affected = 0;
                $holdId = $this->genId('H');
                foreach ($nights as $d) {
                    if (isset($cal[$d]) && $cal[$d]['status'] === 'OPEN') {
                        $cal[$d]['status'] = 'HELD';
                        $cal[$d]['hold_id'] = $holdId;
                        $cal[$d]['version']++;          // version+1，CAS 語意
                        $affected++;
                    }
                }
                if ($affected !== count($nights)) {
                    // 有人搶先（affected 不足）→ 回滾，不寫回
                    return [['ok' => false, 'code' => 409, 'error' => '部分日期已被預訂', 'conflict' => $unavailable], false];
                }
                $data['rooms'][$roomId]['calendar'] = $cal;
                $data['holds'][$holdId] = $this->newHold($holdId, $roomId, $nights, $checkin, $checkout);
                return [['ok' => true, 'code' => 201, 'hold_id' => $holdId, 'expires_at' => $data['holds'][$holdId]['expires_at']], true];
            }

            // 悲觀鎖（預設）：鎖已由檔案鎖持有（等同 SELECT ... FOR UPDATE 鎖住這些列），
            // 鎖內判斷皆可用才更新。
            if ($unavailable !== []) {
                return [['ok' => false, 'code' => 409, 'error' => '部分日期已被預訂', 'conflict' => $unavailable], false];
            }
            $holdId = $this->genId('H');
            foreach ($nights as $d) {
                $cal[$d]['status'] = 'HELD';
                $cal[$d]['hold_id'] = $holdId;
                $cal[$d]['version']++;
            }
            $data['rooms'][$roomId]['calendar'] = $cal;
            $data['holds'][$holdId] = $this->newHold($holdId, $roomId, $nights, $checkin, $checkout);
            return [['ok' => true, 'code' => 201, 'hold_id' => $holdId, 'expires_at' => $data['holds'][$holdId]['expires_at']], true];
        });
    }

    /** 確認（付款成功後呼叫）：HELD → BOOKED。逾時則拒絕（需重新下單/補償）。 */
    public function confirm(string $holdId): array
    {
        return $this->store->transaction(function (array &$data) use ($holdId) {
            $this->expireStaleHolds($data);

            $hold = $data['holds'][$holdId] ?? null;
            if ($hold === null) {
                return [['ok' => false, 'code' => 404, 'error' => 'hold 不存在'], false];
            }
            if ($hold['status'] === 'RELEASED') {
                return [['ok' => false, 'code' => 410, 'error' => 'hold 已逾時釋放，請重新下單'], false];
            }
            if ($hold['status'] === 'CONFIRMED') {
                // 冪等：重複 confirm 同一 hold 視為成功
                return [['ok' => true, 'code' => 200, 'booking_id' => $hold['booking_id'], 'status' => 'CONFIRMED', 'idempotent' => true], false];
            }
            $roomId = $hold['room_id'];
            foreach ($hold['dates'] as $d) {
                $data['rooms'][$roomId]['calendar'][$d]['status'] = 'BOOKED';
            }
            $bookingId = $this->genId('B');
            $data['holds'][$holdId]['status'] = 'CONFIRMED';
            $data['holds'][$holdId]['booking_id'] = $bookingId;
            return [['ok' => true, 'code' => 200, 'booking_id' => $bookingId, 'status' => 'CONFIRMED'], true];
        });
    }

    /** 主動釋放（付款失敗 / 使用者取消）：HELD → OPEN。 */
    public function release(string $holdId): array
    {
        return $this->store->transaction(function (array &$data) use ($holdId) {
            $hold = $data['holds'][$holdId] ?? null;
            if ($hold === null) {
                return [['ok' => false, 'code' => 404, 'error' => 'hold 不存在'], false];
            }
            if ($hold['status'] !== 'HELD') {
                return [['ok' => false, 'code' => 409, 'error' => '只有 HELD 狀態可釋放，目前為 ' . $hold['status']], false];
            }
            $this->freeDates($data, $hold['room_id'], $hold['dates'], $holdId);
            $data['holds'][$holdId]['status'] = 'RELEASED';
            return [['ok' => true, 'code' => 200, 'released' => $hold['dates']], true];
        });
    }

    /** 背景 TTL 掃描器：把所有逾期 HELD 釋放（真實系統由定時工作執行）。 */
    public function sweepExpired(): array
    {
        return $this->store->transaction(function (array &$data) {
            $freed = $this->expireStaleHolds($data);
            return [['ok' => true, 'released_holds' => $freed], $freed !== []];
        });
    }

    /** 房況日曆（讀路徑，走快照；真實系統走讀副本/快取） */
    public function calendar(string $roomId): array
    {
        $data = $this->store->snapshot();
        $room = $data['rooms'][$roomId] ?? null;
        if ($room === null) {
            return ['ok' => false, 'error' => '房源不存在'];
        }
        return ['ok' => true, 'room_id' => $roomId, 'name' => $room['name'], 'calendar' => $room['calendar']];
    }

    public function allRooms(): array
    {
        return $this->store->snapshot()['rooms'] ?? [];
    }

    public function allHolds(): array
    {
        return $this->store->snapshot()['holds'] ?? [];
    }

    // ============ 內部輔助（皆假設已在交易鎖內呼叫） ============

    /** 釋放逾期的 HELD 保留，回傳被釋放的 hold_id 清單。 */
    private function expireStaleHolds(array &$data): array
    {
        $now = time();
        $freed = [];
        foreach (($data['holds'] ?? []) as $hid => $hold) {
            if ($hold['status'] === 'HELD' && $hold['expires_at'] < $now) {
                $this->freeDates($data, $hold['room_id'], $hold['dates'], $hid);
                $data['holds'][$hid]['status'] = 'RELEASED';
                $freed[] = $hid;
            }
        }
        return $freed;
    }

    /** 把指定房源的指定日期格從 HELD（屬於該 hold）退回 OPEN。 */
    private function freeDates(array &$data, string $roomId, array $dates, string $holdId): void
    {
        foreach ($dates as $d) {
            $cell = $data['rooms'][$roomId]['calendar'][$d] ?? null;
            if ($cell !== null && $cell['status'] === 'HELD' && $cell['hold_id'] === $holdId) {
                $data['rooms'][$roomId]['calendar'][$d]['status'] = 'OPEN';
                $data['rooms'][$roomId]['calendar'][$d]['hold_id'] = null;
                $data['rooms'][$roomId]['calendar'][$d]['version']++;
            }
        }
    }

    private function newHold(string $holdId, string $roomId, array $nights, string $checkin, string $checkout): array
    {
        return [
            'hold_id' => $holdId,
            'room_id' => $roomId,
            'dates' => $nights,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'status' => 'HELD',
            'booking_id' => null,
            'expires_at' => time() + self::HOLD_TTL_SECONDS,
        ];
    }

    /**
     * 計算入住區間佔用的「夜」。退房日不佔（6/1–6/4 = 6/1,6/2,6/3）。
     * $inclusiveEnd=true 時含結束日（用於建房源庫存日曆）。
     *
     * @return string[] YYYY-MM-DD 清單
     */
    private function nightsBetween(string $from, string $to, bool $inclusiveEnd): array
    {
        $start = strtotime($from);
        $end = strtotime($to);
        if ($start === false || $end === false) {
            return [];
        }
        if ($inclusiveEnd) {
            if ($end < $start) {
                return [];
            }
        } elseif ($end <= $start) {
            return [];
        }
        $dates = [];
        $cursor = $start;
        $last = $inclusiveEnd ? $end : ($end - 86400);
        while ($cursor <= $last) {
            $dates[] = gmdate('Y-m-d', $cursor);
            $cursor += 86400;
        }
        return $dates;
    }

    private function genId(string $prefix): string
    {
        return $prefix . substr(bin2hex(random_bytes(4)), 0, 6);
    }
}
