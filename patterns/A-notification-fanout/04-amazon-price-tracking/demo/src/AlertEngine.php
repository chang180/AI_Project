<?php
declare(strict_types=1);

/**
 * 提醒引擎（本題核心：條件匹配 + 智能去抖狀態機 + 冪等去重）
 * ==================================================
 * 一筆價格變動進來，要做三件事：
 *   1) 條件匹配：透過反向索引（Watchlist::watchersFor）取出「現價 <= 觸發價」的 watcher，
 *      而不是逐條掃描全部 watch 比大小。
 *   2) 智能去抖：每條 watch 有狀態機 ARMED → TRIGGERED → COOLDOWN：
 *        - ARMED 且達標 → 觸發、發提醒、進 COOLDOWN（價格抖動不重發）。
 *        - COOLDOWN 期間即使持續低於門檻 → 不再發。
 *        - 價格「回升超過觸發價 × (1+HYSTERESIS)」才 re-arm 回 ARMED（遲滯帶，
 *          避免在門檻線上下抖動造成反覆觸發）。
 *   3) 冪等：每次觸發產生冪等鍵 hash(watch_id + 觸發週期)，投遞層去重，
 *      保證 at-least-once 不會變成 many-times。
 *
 * 真實系統把「反向索引」換成 Redis ZSET、「通知投遞」換成 Kafka/SQS + 寄信服務即可，
 *   底下的狀態機與去抖邏輯完全不變——這正是面試最常被追問的部分。
 */
final class AlertEngine
{
    /** 觸發後冷卻秒數（demo 用短秒數方便觀察；真實多為數小時～一天） */
    private const COOLDOWN_SECONDS = 30;
    /** 遲滯帶：價格須回升超過觸發價的此比例才 re-arm（避免門檻線抖動反覆觸發） */
    private const HYSTERESIS = 0.05;

    /** @var array<int,array<string,mixed>> 本次處理產生的提醒（模擬投遞佇列內容） */
    private array $delivered = [];
    /** @var array<string,bool> 冪等鍵集合（模擬投遞層去重） */
    private array $idempotency = [];

    public function __construct(
        private Watchlist $watchlist,
        private string $dataDir
    ) {
        $idxFile = $this->dataDir . '/alerts.json';
        if (is_file($idxFile)) {
            $this->idempotency = json_decode((string) file_get_contents($idxFile), true) ?: [];
        }
    }

    /**
     * 處理一筆價格事件：匹配 → 去抖 → （通過者）通知 fan-out。
     *
     * @param array{sku:string,price:float,prev:?float} $event
     * @return array<int,array<string,mixed>> 本次實際發出的提醒（去抖後）
     */
    public function onPriceEvent(array $event): array
    {
        $sku = $event['sku'];
        $price = (float) $event['price'];
        $now = time();
        $fired = [];

        // 1) 條件匹配：反向索引取出達標 watcher（現價 <= 觸發價）
        $hitWatchers = $this->watchlist->watchersFor($sku, $price);
        $hitIds = array_map(static fn(array $w): string => $w['watch_id'], $hitWatchers);

        // 同時取得「該 sku 的全部 watch」以處理 re-arm（價格回升的未達標者）
        foreach ($this->watchlist->all() as $w) {
            if ($w['sku'] !== $sku) {
                continue;
            }
            $w['last_price'] = $price;
            $isHit = in_array($w['watch_id'], $hitIds, true);

            if ($isHit) {
                // 達標：只有 ARMED 才會真正觸發發送（去抖核心）
                if ($w['state'] === 'ARMED') {
                    $w['state'] = 'TRIGGERED';
                    $w['cooldown_until'] = $now + self::COOLDOWN_SECONDS;
                    $alert = $this->fanout($w, $price, $now);
                    if ($alert !== null) {
                        $w['alerts_sent'] = (int) $w['alerts_sent'] + 1;
                        $fired[] = $alert;
                    }
                    // 觸發後立即進 COOLDOWN（TRIGGERED 是瞬態，落地為 COOLDOWN）
                    $w['state'] = 'COOLDOWN';
                }
                // 若已是 TRIGGERED / COOLDOWN：達標但不重發（抖動被吸收）
            } else {
                // 未達標：檢查是否該 re-arm（價格回升超過遲滯帶）
                $rearmLevel = (float) $w['trigger_price'] * (1 + self::HYSTERESIS);
                if ($w['state'] === 'COOLDOWN' && $price >= $rearmLevel && $now >= (int) $w['cooldown_until']) {
                    $w['state'] = 'ARMED'; // 回升夠多且冷卻結束 → 重新武裝
                }
            }
            $this->watchlist->save($w);
        }

        $this->persistIdem();
        return $fired;
    }

    /**
     * 通知 fan-out（模擬投遞佇列 + 冪等去重）。
     * 冪等鍵 = hash(watch_id + 觸發週期)；同一週期重複觸發直接跳過，保證不重發。
     *
     * @return array<string,mixed>|null 實際投遞的提醒；被冪等去重則回 null
     */
    private function fanout(array $watch, float $price, int $now): ?array
    {
        // 觸發週期：以冷卻區間為單位，同一冷卻週期內視為同一次觸發
        $epoch = intdiv($now, self::COOLDOWN_SECONDS);
        $key = hash('sha256', $watch['watch_id'] . ':' . $epoch);
        if (isset($this->idempotency[$key])) {
            return null; // 冪等：此週期已發過
        }
        $this->idempotency[$key] = true;

        $alert = [
            'alert_id'  => substr($key, 0, 12),
            'watch_id'  => $watch['watch_id'],
            'user_id'   => $watch['user_id'],
            'sku'       => $watch['sku'],
            'price'     => $price,
            'trigger_price' => $watch['trigger_price'],
            'sent_at'   => gmdate('c', $now),
            'channel'   => 'email', // 真實系統依使用者偏好 fan-out 到 Email/Push/App
        ];
        $this->delivered[] = $alert;
        return $alert;
    }

    /** @return array<int,array<string,mixed>> 本 process 累積投遞的提醒 */
    public function deliveredAlerts(): array
    {
        return $this->delivered;
    }

    private function persistIdem(): void
    {
        file_put_contents(
            $this->dataDir . '/alerts.json',
            json_encode($this->idempotency, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
