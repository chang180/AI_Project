<?php
declare(strict_types=1);

/**
 * Worker（非同步執行者）
 * --------------------------------------------------
 * 對應真實元件：佇列消費者，從 TaskQueue 取出 QUEUED 的 run，呼叫 LLM 產生結果，
 *              依結果推進狀態機（DONE / RETRY / FAILED），並投遞通知。
 *
 * 本 demo 的 LLM 呼叫為「假呼叫」（fakeLlmCall）：依任務 prompt 產生確定性文字，
 * 並可故意模擬偶發失敗以示範重試。其餘（取出、狀態轉移、重試、通知）皆為真實邏輯。
 */
final class Worker
{
    public function __construct(
        private Store $store,
        private TaskQueue $queue,
        private int $maxRetry = 2,
    ) {}

    /**
     * 跑一輪：把目前所有 QUEUED 的 run 執行掉。
     * 回傳每個 run 的執行結果摘要（給測試頁顯示）。
     *
     * @return array<int,array{run_id:int,status:string,detail:string}>
     */
    public function runOnce(): array
    {
        $report = [];

        foreach ($this->queue->dequeueAll() as $run) {
            $runId = (int) $run['run_id'];
            $attempt = (int) $run['attempt'] + 1; // 本次為第幾次嘗試

            $this->queue->markRunning($runId);

            $task = $this->store->getTask($run['task_id']);
            if ($task === null) {
                $this->queue->markFailed($runId, $this->maxRetry, '任務已不存在', $this->maxRetry);
                $report[] = ['run_id' => $runId, 'status' => 'FAILED', 'detail' => '任務不存在'];
                continue;
            }

            try {
                // === 真實系統會在此呼叫 LLM API（OpenAI / Anthropic …），此處為假呼叫 ===
                $result = $this->fakeLlmCall($task, $attempt);

                $this->queue->markDone($runId, $result);
                $this->deliverNotification($task, $result); // 通知投遞（示意）
                $report[] = ['run_id' => $runId, 'status' => 'DONE', 'detail' => $result];
            } catch (\RuntimeException $e) {
                $this->queue->markFailed($runId, $attempt, $e->getMessage(), $this->maxRetry);
                $finalStatus = ($attempt < $this->maxRetry) ? 'RETRY→QUEUED' : 'FAILED';
                $report[] = ['run_id' => $runId, 'status' => $finalStatus, 'detail' => $e->getMessage()];
            }
        }

        return $report;
    }

    /**
     * 假的 LLM 呼叫：依任務內容產生確定性結果文字。
     * 若任務標記 force_fail 且尚未到最後一次嘗試，故意丟例外以示範重試路徑。
     */
    private function fakeLlmCall(array $task, int $attempt): string
    {
        // 示範重試：強制失敗的任務在最後一次嘗試前都會丟例外
        if (!empty($task['force_fail']) && $attempt <= $this->maxRetry) {
            throw new \RuntimeException("模擬 LLM 逾時（第 {$attempt} 次嘗試）");
        }

        $prompt = (string) ($task['prompt'] ?? '（無提示）');
        $now = gmdate('Y-m-d H:i:s');

        // 確定性「生成」：把 prompt 包裝成一段摘要文字
        return "🤖（{$now} UTC 產生）針對任務「{$task['name']}」的 LLM 回覆：\n"
            . "已處理提示「{$prompt}」，產出摘要 "
            . substr(hash('sha256', $prompt . $task['id']), 0, 12)
            . "（第 {$attempt} 次嘗試成功）";
    }

    /**
     * 通知投遞（示意）：真實系統會推 push / email / webhook，並確保至少一次投遞。
     */
    private function deliverNotification(array $task, string $result): void
    {
        $this->store->appendNotification([
            'task_id' => $task['id'],
            'channel' => $task['notify_channel'] ?? 'inbox',
            'message' => "任務「{$task['name']}」已完成",
            'preview' => $this->utf8Cut($result, 40),
            'sent_at' => gmdate('c'),
        ]);
    }

    /** 取前 N 個 UTF-8 字元（不依賴 mbstring，避免切斷多位元組字元） */
    private function utf8Cut(string $s, int $chars): string
    {
        if (preg_match('/^.{0,' . $chars . '}/us', $s, $m)) {
            return $m[0];
        }
        return $s;
    }
}
