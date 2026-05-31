<?php
declare(strict_types=1);

/**
 * 投遞器（推播閘道）
 * --------------------------------------------------
 * 消費通知佇列裡的任務，把警報送到裝置。實作本組共通的投遞保證心法：
 *   - at-least-once：寧可重送也不可漏送（漏報會死人）。
 *   - 冪等去重：以 (event_id, device_id) 為冪等鍵，已成功者直接跳過，避免同一警報重複響。
 *   - 指數退避 + 抖動重試：暫時失敗時退避重試。
 *   - DLQ：地震時效性極短，重試耗盡仍失敗則進死信佇列供事後分析（補送已無意義）。
 *
 * ⚠️ 示意 vs 真實：這裡的 push() 是「模擬投遞」（隨機延遲與失敗），不會真的呼叫 APNs/FCM。
 *    要接真實推播，把 push() 換成 APNs/FCM SDK 呼叫即可，其餘邏輯（去重/重試/DLQ）不變。
 */
final class Dispatcher
{
    private const MAX_ATTEMPTS = 3;
    private const BASE_BACKOFF_MS = 50;

    /** @var array<int,array> 死信佇列：重試耗盡仍失敗的任務 */
    private array $dlq = [];

    public function __construct(private Store $store) {}

    /**
     * 消費並投遞一批任務。
     *
     * @param array<int,array> $tasks 來自 NotificationQueue::drain()
     * @return array{sent:int,deduped:int,dlq:int,results:array<int,array>}
     */
    public function dispatch(array $tasks): array
    {
        $sent = 0;
        $deduped = 0;
        $results = [];

        foreach ($tasks as $task) {
            $eventId  = $task['event_id'];
            $deviceId = $task['device_id'];

            // ── 冪等去重：已成功投遞過就跳過（at-least-once 的去重半）──
            if ($this->store->isDelivered($eventId, $deviceId)) {
                $deduped++;
                $results[] = $this->result($task, 'deduped', 0, 0);
                continue;
            }

            $this->store->recordDelivery($eventId, $deviceId, ['status' => 'queued', 'attempts' => 0]);

            // ── 指數退避重試 ──
            $attempt = 0;
            $delivered = false;
            $totalLatencyMs = 0;

            while ($attempt < self::MAX_ATTEMPTS) {
                $attempt++;
                [$ok, $latencyMs] = $this->push($task);
                $totalLatencyMs += $latencyMs;

                if ($ok) {
                    $delivered = true;
                    break;
                }
                // 退避：base * 2^(attempt-1) + 抖動
                if ($attempt < self::MAX_ATTEMPTS) {
                    $backoff = self::BASE_BACKOFF_MS * (2 ** ($attempt - 1));
                    $jitter = random_int(0, self::BASE_BACKOFF_MS);
                    $totalLatencyMs += $backoff + $jitter;
                }
            }

            if ($delivered) {
                $sent++;
                $this->store->recordDelivery($eventId, $deviceId, [
                    'status' => 'sent', 'attempts' => $attempt, 'latency_ms' => $totalLatencyMs,
                ]);
                $results[] = $this->result($task, 'sent', $attempt, $totalLatencyMs);
            } else {
                // ── 重試耗盡 → DLQ ──
                $this->store->recordDelivery($eventId, $deviceId, [
                    'status' => 'failed', 'attempts' => $attempt, 'latency_ms' => $totalLatencyMs,
                ]);
                $this->dlq[] = $task;
                $results[] = $this->result($task, 'failed', $attempt, $totalLatencyMs);
            }
        }

        return [
            'sent'    => $sent,
            'deduped' => $deduped,
            'dlq'     => count($this->dlq),
            'results' => $results,
        ];
    }

    /**
     * 模擬推播到 APNs/FCM（示意）。
     * 回傳 [是否成功, 模擬延遲毫秒]。約 15% 機率暫時失敗以演示重試。
     *
     * @return array{0:bool,1:int}
     */
    private function push(array $task): array
    {
        $latencyMs = random_int(20, 120);       // 模擬網路 / 推播商延遲
        $ok = random_int(1, 100) > 15;          // ~85% 成功
        return [$ok, $latencyMs];
    }

    private function result(array $task, string $status, int $attempts, int $latencyMs): array
    {
        return [
            'device_id'   => $task['device_id'],
            'distance_km' => $task['distance_km'],
            'eta_seconds' => $task['eta_seconds'],
            'status'      => $status,
            'attempts'    => $attempts,
            'latency_ms'  => $latencyMs,
        ];
    }

    /** @return array<int,array> */
    public function dlq(): array
    {
        return $this->dlq;
    }
}
