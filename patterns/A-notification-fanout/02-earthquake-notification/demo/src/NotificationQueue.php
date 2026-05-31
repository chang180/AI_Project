<?php
declare(strict_types=1);

/**
 * 通知佇列（fan-out on write 的展開點）
 * --------------------------------------------------
 * 地理篩選算出收件人名單後，把「一個地震事件」展開成「N 筆逐裝置投遞任務」入列。
 * 真實系統用 Kafka / SQS，依 region 分區並行消費；這裡用記憶體陣列模擬單機佇列，
 * 聚焦呈現 fan-out 展開與冪等鍵的概念。
 *
 * 每筆任務帶冪等鍵 (event_id, device_id)，下游投遞器據此去重，達成 at-least-once。
 */
final class NotificationQueue
{
    /** @var array<int,array> 待投遞任務 */
    private array $tasks = [];

    /**
     * Fan-out：把一個事件對一群收件人展開成逐裝置任務。
     *
     * @param array<int,array> $recipients 地理篩選後的收件人（含 distance_km）
     */
    public function fanOut(array $event, array $recipients): int
    {
        foreach ($recipients as $r) {
            // 以 S 波速度估算抵達秒數，作為推播 payload 的倒數（領域知識帶入）
            $sWaveKmPerSec = 3.5;
            $eta = (int) round(((float) $r['distance_km']) / $sWaveKmPerSec);

            $this->tasks[] = [
                'idempotency_key' => $event['event_id'] . '|' . $r['device_id'], // 冪等鍵
                'event_id'        => $event['event_id'],
                'device_id'       => $r['device_id'],
                'device_token'    => $r['device_token'] ?? '',
                'distance_km'     => $r['distance_km'],
                'eta_seconds'     => $eta,
                'payload'         => [
                    'event_id'    => $event['event_id'],
                    'magnitude'   => $event['magnitude'],
                    'eta_seconds' => $eta,
                    'action'      => '就地掩護，遠離窗戶',
                ],
            ];
        }
        return count($recipients);
    }

    /** @return array<int,array> 取出全部任務（單機示意；真實系統由多 worker 分區並行消費） */
    public function drain(): array
    {
        $tasks = $this->tasks;
        $this->tasks = [];
        return $tasks;
    }

    public function size(): int
    {
        return count($this->tasks);
    }
}
