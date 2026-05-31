<?php
declare(strict_types=1);

/**
 * 投遞佇列 + 投遞任務狀態機（模擬 deliveries 表 / 訊息佇列）
 * --------------------------------------------------
 * 每筆投遞是一個狀態機：
 *   PENDING ──取出投遞──▶ DELIVERING ──成功──▶ DELIVERED
 *                                    └─失敗且未達上限──▶ RETRY（設定 next_retry_at，退避後再投）
 *                                    └─失敗且達上限────▶ DEAD（進 DLQ）
 *
 * 真實系統：用 Kafka / SQS（依 endpoint 分區），重試靠延遲佇列承載 next_retry_at，
 * 由獨立 worker 程序池並發投遞。這裡用 JSON 檔持久化任務，呈現狀態流轉與 DLQ。
 */
final class DeliveryQueue
{
    public const PENDING    = 'PENDING';
    public const DELIVERING = 'DELIVERING';
    public const DELIVERED  = 'DELIVERED';
    public const RETRY      = 'RETRY';
    public const DEAD       = 'DEAD';

    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/deliveries.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, '[]');
        }
    }

    /**
     * Fan-out 入列：為某事件 × 某端點建立一筆 PENDING 投遞任務。
     * event_id 全程不變，作為接收方去重的 Idempotency-Key。
     */
    public function enqueue(string $eventId, string $eventType, array $payload, string $endpointId): array
    {
        $rows = $this->read();
        $task = [
            'delivery_id'   => 'dl_' . substr(bin2hex(random_bytes(6)), 0, 8),
            'event_id'      => $eventId,        // = Idempotency-Key
            'event_type'    => $eventType,
            'endpoint_id'   => $endpointId,     // = 分區鍵（順序 / 隔離）
            'payload'       => $payload,
            'status'        => self::PENDING,
            'attempts'      => 0,
            'last_code'     => null,
            'next_retry_at' => 0,               // 0 = 立即可投
            'created_at'    => gmdate('c'),
            'updated_at'    => gmdate('c'),
        ];
        $rows[] = $task;
        $this->write($rows);
        return $task;
    }

    /**
     * 取出「現在可投遞」的任務：狀態為 PENDING/RETRY 且 next_retry_at <= now。
     * 真實系統由佇列依分區交付；這裡掃描全表示意。
     */
    public function dueTasks(?int $now = null): array
    {
        $now = $now ?? time();
        $due = [];
        foreach ($this->read() as $t) {
            if (in_array($t['status'], [self::PENDING, self::RETRY], true) && $t['next_retry_at'] <= $now) {
                $due[] = $t;
            }
        }
        return $due;
    }

    public function get(string $deliveryId): ?array
    {
        foreach ($this->read() as $t) {
            if ($t['delivery_id'] === $deliveryId) {
                return $t;
            }
        }
        return null;
    }

    /** 覆寫某筆任務（狀態流轉時呼叫） */
    public function save(array $task): void
    {
        $rows = $this->read();
        foreach ($rows as $i => $t) {
            if ($t['delivery_id'] === $task['delivery_id']) {
                $task['updated_at'] = gmdate('c');
                $rows[$i] = $task;
                $this->write($rows);
                return;
            }
        }
    }

    /** @return array<int,array> 全部任務 */
    public function all(): array
    {
        return $this->read();
    }

    /** DLQ：所有 DEAD 任務 */
    public function deadLetters(): array
    {
        return array_values(array_filter($this->read(), static fn($t) => $t['status'] === self::DEAD));
    }

    private function read(): array
    {
        $json = (string) file_get_contents($this->file);
        $rows = json_decode($json, true);
        return is_array($rows) ? $rows : [];
    }

    private function write(array $rows): void
    {
        file_put_contents(
            $this->file,
            json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
