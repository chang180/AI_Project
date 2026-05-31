<?php
declare(strict_types=1);

/**
 * 推論請求的生命週期狀態機
 * --------------------------------------------------
 * QUEUED   在佇列中等待被排程器拉進批次
 * PREFILL  剛被拉進批次，正在處理整段 prompt（一次性，本 demo 視為 1 個 step）
 * DECODING 自迴歸逐 token 生成中（每個 step 生 1 個 token）
 * DONE     生成達到計畫長度，讓出 GPU 槽位
 *
 * 記錄每請求的 token 數、進佇列 / 完成的 step，用以統計等待與耗時。
 */
final class Request
{
    public const QUEUED   = 'QUEUED';
    public const PREFILL  = 'PREFILL';
    public const DECODING = 'DECODING';
    public const DONE     = 'DONE';

    public string $state = self::QUEUED;
    public int $generated = 0;        // 已生成 token 數
    public int $startStep = -1;       // 被拉進批次（開始 prefill）的 step
    public int $finishStep = -1;      // 完成的 step
    /** @var string[] 已生成的 token（占位） */
    public array $tokens = [];

    public function __construct(
        public readonly string $id,
        public readonly string $tenant,   // 計費 / 限流歸屬
        public readonly int $priority,    // 0=高(付費) 1=一般 2=batch
        public readonly string $prompt,
        public readonly int $plannedTokens, // 由「模型」決定的目標輸出長度
        public readonly int $enqueueStep  // 進佇列的 step
    ) {}

    public function isDone(): bool
    {
        return $this->state === self::DONE;
    }

    /** 等待 step 數：被拉進批次前在佇列等了多久 */
    public function waitSteps(): int
    {
        return $this->startStep < 0 ? 0 : max(0, $this->startStep - $this->enqueueStep);
    }

    /** 在批次中佔用 GPU 槽位的 step 數（prefill 1 + decode N） */
    public function serviceSteps(): int
    {
        if ($this->startStep < 0 || $this->finishStep < 0) {
            return 0;
        }
        return $this->finishStep - $this->startStep + 1;
    }

    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'tenant'    => $this->tenant,
            'priority'  => $this->priority,
            'state'     => $this->state,
            'generated' => $this->generated,
            'planned'   => $this->plannedTokens,
            'wait'      => $this->waitSteps(),
        ];
    }
}
