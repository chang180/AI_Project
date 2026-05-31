<?php
declare(strict_types=1);

/**
 * 任務佇列（TaskQueue）+ 狀態機
 * --------------------------------------------------
 * 對應真實元件：訊息佇列（SQS / RabbitMQ / Kafka）+ 任務狀態表。
 *
 * 任務「執行（run）」的狀態機：
 *   SCHEDULED ──(scheduler tick 到期)──▶ QUEUED ──(worker 取出)──▶ RUNNING
 *       RUNNING ──成功──▶ DONE
 *       RUNNING ──失敗且還可重試──▶ RETRY ──(退避後)──▶ QUEUED
 *       RUNNING ──失敗且重試用盡──▶ FAILED（投遞死信 / 通知使用者）
 *
 * 注意：這裡的狀態指的是「單次執行（run）」的生命週期，存於 runs.json；
 *       任務本身（task）的排程狀態（SCHEDULED / DISPATCHED）存於 tasks.json，由 Scheduler 管理。
 *
 * 冪等（idempotency）：
 *   每次觸發都有唯一冪等鍵 = task_id@fire_at。enqueue 前先檢查是否已存在同鍵的 run，
 *   存在就直接略過，確保「同一觸發不會被排兩次」（防止 scheduler 重複掃描造成重複跑 LLM）。
 */
final class TaskQueue
{
    public function __construct(private Store $store) {}

    /**
     * 入列一次觸發。回傳 true 表示真的入列；false 表示因冪等鍵重複而略過。
     */
    public function enqueue(string $taskId, int $fireAt, string $idempotencyKey): bool
    {
        // 冪等去重：同一冪等鍵已存在 → 略過（這是避免「同一觸發跑兩次」的關鍵）
        if ($this->store->findRunByKey($idempotencyKey) !== null) {
            return false;
        }

        $this->store->putRun([
            'run_id' => $this->store->nextRunId(),
            'task_id' => $taskId,
            'idempotency_key' => $idempotencyKey,
            'fire_at' => $fireAt,
            'status' => 'QUEUED',
            'attempt' => 0,
            'result' => null,
            'error' => null,
            'queued_at' => gmdate('c'),
            'finished_at' => null,
        ]);
        return true;
    }

    /**
     * 取出所有 QUEUED 的 run（FIFO，依 run_id）。真實系統由 worker 競爭性消費。
     *
     * @return array<int,array>
     */
    public function dequeueAll(): array
    {
        $queued = [];
        foreach ($this->store->allRuns() as $run) {
            if ($run['status'] === 'QUEUED') {
                $queued[] = $run;
            }
        }
        usort($queued, fn($a, $b) => $a['run_id'] <=> $b['run_id']);
        return $queued;
    }

    public function markRunning(int $runId): void
    {
        $this->store->updateRun($runId, ['status' => 'RUNNING']);
    }

    public function markDone(int $runId, string $result): void
    {
        $this->store->updateRun($runId, [
            'status' => 'DONE',
            'result' => $result,
            'error' => null,
            'finished_at' => gmdate('c'),
        ]);
    }

    /**
     * 標記失敗：還有重試額度 → RETRY 後重新入列（QUEUED）；用盡 → FAILED。
     */
    public function markFailed(int $runId, int $attempt, string $error, int $maxRetry): void
    {
        if ($attempt < $maxRetry) {
            // 真實系統會做指數退避（backoff）並延遲重投；demo 直接重新入列
            $this->store->updateRun($runId, [
                'status' => 'QUEUED',
                'attempt' => $attempt,
                'error' => $error . '（將重試）',
            ]);
        } else {
            $this->store->updateRun($runId, [
                'status' => 'FAILED',
                'attempt' => $attempt,
                'error' => $error . '（重試用盡，投遞死信 / 通知使用者）',
                'finished_at' => gmdate('c'),
            ]);
        }
    }
}
