<?php
declare(strict_types=1);

/**
 * 投遞引擎（模擬投遞 Worker）
 * --------------------------------------------------
 * 流程：取出可投遞任務 → HMAC 簽章 → 投遞 → 依結果走狀態機。
 *   - 成功（2xx）            → DELIVERED
 *   - 失敗且未達重試上限      → RETRY，計算「指數退避 + 抖動」後的 next_retry_at
 *   - 失敗且達上限            → DEAD（進 DLQ），並可自動停用端點
 *
 * ⚠️ 為何用「模擬投遞結果」而非真的發 HTTP？
 *   PHP 內建伺服器（php -S）是單執行緒：若 worker 在同一進程內用 curl 打自己的
 *   /receive，會「自鎖」——伺服器忙著等 worker，而 worker 等伺服器回應。
 *   因此預設 deliverSimulated() 依端點的 mode 直接判定回應（success/fail/slow），
 *   不阻塞、可重現。真實系統用獨立 worker 程序池並發地真正發 HTTP。
 *   deliverViaHttp() 保留示範如何對內建 /receive 端點走完整驗簽流程。
 */
final class Dispatcher
{
    /** 重試上限：超過即進 DLQ */
    private const MAX_ATTEMPTS = 6;
    /** 退避基數（秒）：delay = BASE * 2^(attempt-1) + jitter */
    private const BASE_DELAY = 1;

    public function __construct(
        private EndpointStore $endpoints,
        private DeliveryQueue $queue,
        private Signer $signer,
    ) {}

    /**
     * 跑一輪投遞：處理所有「現在到期」的任務。
     * @return array<int,array> 本輪每筆任務的處理摘要（供測試頁顯示）
     */
    public function runOnce(?int $now = null): array
    {
        $now = $now ?? time();
        $log = [];
        foreach ($this->queue->dueTasks($now) as $task) {
            $log[] = $this->deliver($task, $now);
        }
        return $log;
    }

    /** 投遞單一任務並推進狀態機 */
    private function deliver(array $task, int $now): array
    {
        $ep = $this->endpoints->get($task['endpoint_id']);
        if (!$ep) {
            $task['status'] = DeliveryQueue::DEAD;
            $task['last_code'] = 0;
            $this->queue->save($task);
            return ['delivery_id' => $task['delivery_id'], 'result' => 'endpoint 不存在 → DEAD', 'status' => $task['status'], 'attempts' => $task['attempts']];
        }

        $task['status'] = DeliveryQueue::DELIVERING;
        $task['attempts']++;

        // 1) 簽章：body = 事件 JSON；標頭含 timestamp + HMAC 簽章 + Idempotency-Key
        $body = json_encode($task['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = $this->signer->sign($ep['secret'], $body, $now);
        $headers['Idempotency-Key'] = $task['event_id'];   // 接收方據此去重
        $headers['X-Webhook-Id']    = $task['delivery_id'];

        // 2) 投遞（預設模擬；真實系統發 HTTP 並讀回應碼）
        $code = $this->deliverSimulated($ep, $body, $headers);
        $task['last_code'] = $code;

        // 3) 依結果推進狀態機
        if ($code >= 200 && $code < 300) {
            $task['status'] = DeliveryQueue::DELIVERED;
            $task['next_retry_at'] = 0;
            $this->queue->save($task);
            return ['delivery_id' => $task['delivery_id'], 'result' => "投遞成功 (HTTP $code)", 'status' => $task['status'], 'attempts' => $task['attempts'], 'sig' => $headers['X-Webhook-Signature']];
        }

        // 失敗：判斷是否還能重試
        if ($task['attempts'] >= self::MAX_ATTEMPTS) {
            $task['status'] = DeliveryQueue::DEAD;           // 進 DLQ
            $task['next_retry_at'] = 0;
            $this->queue->save($task);
            $this->endpoints->disable($ep['endpoint_id']);   // 連續失敗 → 自動停用
            return ['delivery_id' => $task['delivery_id'], 'result' => "達重試上限 (HTTP $code) → 進 DLQ，停用端點", 'status' => $task['status'], 'attempts' => $task['attempts']];
        }

        $delay = $this->backoffWithJitter($task['attempts']);
        $task['status'] = DeliveryQueue::RETRY;
        $task['next_retry_at'] = $now + $delay;              // 退避後才會被 dueTasks 取出
        $this->queue->save($task);
        return ['delivery_id' => $task['delivery_id'], 'result' => "投遞失敗 (HTTP $code) → {$delay}s 後重試", 'status' => $task['status'], 'attempts' => $task['attempts'], 'next_retry_in' => $delay];
    }

    /**
     * 指數退避 + 抖動。
     *   base = BASE_DELAY * 2^(attempt-1)
     *   jitter = [0, base) 的隨機值 → 打散驚群，避免大量端點同時重試
     */
    private function backoffWithJitter(int $attempt): int
    {
        $base = self::BASE_DELAY * (2 ** ($attempt - 1));
        $jitter = random_int(0, max(1, $base));
        return (int) ($base + $jitter);
    }

    /**
     * 模擬投遞：依端點 mode 直接回傳 HTTP 狀態碼（不發真實 HTTP，避免單執行緒自鎖）。
     *   success → 200；fail → 500；slow → 504（示意逾時）
     * 簽章標頭已由 deliver() 算好並隨請求送出；真實接收端會用 Signer::verify
     * 驗證（見內建 /receive 路由示範完整驗簽往返）。
     */
    private function deliverSimulated(array $ep, string $body, array $headers): int
    {
        return match ($ep['mode']) {
            'fail'  => 500,
            'slow'  => 504,
            default => 200,
        };
    }

    /**
     * （示範用，預設不啟用）真的對內建 /receive 端點發 HTTP。
     * 真實 worker 即如此運作，但在單執行緒 php -S 下對自己發會自鎖，僅供參考。
     */
    public function deliverViaHttp(string $url, string $body, array $headers): int
    {
        $ch = curl_init($url);
        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $hdr),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code ?: 0;
    }
}
