<?php
declare(strict_types=1);

/**
 * 點擊事件攝入層（對應真實系統的 收集服務 + Kafka 消費端去重）
 * --------------------------------------------------------------------
 * 真實系統：點擊量極大，收集服務只負責把事件丟進 Kafka（at-least-once，
 * 寧可重送也不丟事件）。但「重送」會造成同一次點擊被算兩次 → 重複計費。
 * 因此下游必須以 click_id 做**冪等去重**，把 at-least-once 收斂成
 * **exactly-once 計費**。
 *
 * 這裡用一個 process/檔案層級的 seen-set（已見過的 click_id 集合）模擬
 * 去重狀態（真實系統會放在 RocksDB / Redis / Flink keyed-state 並設 TTL）。
 * 凡 click_id 已存在於 seen-set 的事件一律丟棄，確保每個 click_id 只被
 * 計費一次。
 */
final class ClickIngest
{
    /** @var array<string,true> 已見過的 click_id 集合（冪等去重鍵） */
    private array $seen = [];

    private int $accepted = 0;   // 通過去重、首次出現的事件數
    private int $duplicate = 0;  // 因 click_id 重複而丟棄的事件數

    /**
     * 攝入單一點擊事件，回傳是否為「首次出現」（true=接受，false=重複丟棄）。
     * 事件格式：['click_id'=>..., 'ad_id'=>..., 'ip'=>..., 'ts'=>...]
     */
    public function ingest(array $event): bool
    {
        $clickId = (string) ($event['click_id'] ?? '');
        if ($clickId === '') {
            // 沒有 click_id 無法冪等，真實系統會打回上游補；這裡視為丟棄
            $this->duplicate++;
            return false;
        }
        if (isset($this->seen[$clickId])) {
            // 重複投遞（at-least-once 的副作用）→ 丟棄，不重複計費
            $this->duplicate++;
            return false;
        }
        $this->seen[$clickId] = true;
        $this->accepted++;
        return true;
    }

    /** 已用過的 seen-set，可供持久化（真實系統存 Redis/RocksDB 並設 TTL） */
    public function seenSet(): array
    {
        return array_keys($this->seen);
    }

    /** 還原 seen-set（從持久化狀態載入） */
    public function loadSeen(array $clickIds): void
    {
        foreach ($clickIds as $id) {
            $this->seen[(string) $id] = true;
        }
    }

    public function acceptedCount(): int
    {
        return $this->accepted;
    }

    public function duplicateCount(): int
    {
        return $this->duplicate;
    }

    public function reset(): void
    {
        $this->seen = [];
        $this->accepted = 0;
        $this->duplicate = 0;
    }
}
