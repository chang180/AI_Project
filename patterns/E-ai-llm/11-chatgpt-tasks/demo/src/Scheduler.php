<?php
declare(strict_types=1);

/**
 * 排程器（Scheduler）
 * --------------------------------------------------
 * 對應真實元件：cron-like 排程器 / 時間輪（timing wheel）/ 延遲佇列（delayed queue）。
 *
 * 職責：
 *  - 管理每個任務的「下次執行時間」(next_run_at)。
 *  - tick()：以「目前邏輯時間」掃出所有到期任務，丟進 TaskQueue（狀態 SCHEDULED → QUEUED）。
 *  - 週期任務（interval）入列後，立刻依間隔排定下一次 next_run_at（catch-up：對齊到未來）。
 *  - 一次性任務（once）執行後不再排定。
 *
 * 真實系統做法：
 *  - 小規模：DB 一張 tasks 表 + 一支 cron worker 每分鐘 SELECT ... WHERE next_run_at <= now()。
 *  - 大規模：時間輪 / Redis ZSET（score = 到期時間戳）/ 訊息佇列原生延遲投遞（SQS DelaySeconds、RabbitMQ TTL+DLX）。
 *  - 為避免「驚群」與重複掃描，掃描動作需配合分散式鎖或租約（lease）。本 demo 以單行程示範核心邏輯。
 */
final class Scheduler
{
    public function __construct(
        private Store $store,
        private TaskQueue $queue,
    ) {}

    /**
     * 推進邏輯時間到 $nowTs，掃出所有 next_run_at <= now 的任務並入列。
     * 回傳本次入列的任務摘要（給測試頁顯示）。
     *
     * @return array<int,array{id:string,name:string,fire_at:int}>
     */
    public function tick(int $nowTs): array
    {
        $fired = [];

        foreach ($this->store->allTasks() as $task) {
            // 只處理「啟用中且已排定下次時間」的任務
            if (($task['status'] ?? '') !== 'SCHEDULED') {
                continue;
            }
            $nextRun = (int) ($task['next_run_at'] ?? 0);
            if ($nextRun > $nowTs) {
                continue; // 尚未到期
            }

            // 到期 → 產生本次觸發的冪等鍵（任務 id + 觸發時間點），確保同一觸發只入列一次
            $fireAt = $nextRun;
            $idempotencyKey = $task['id'] . '@' . $fireAt;

            $enqueued = $this->queue->enqueue($task['id'], $fireAt, $idempotencyKey);

            if ($enqueued) {
                $fired[] = ['id' => $task['id'], 'name' => $task['name'], 'fire_at' => $fireAt];
            }

            // 依任務型態決定下一步
            if ($task['type'] === 'interval') {
                // 週期任務：對齊到「嚴格大於 now」的下一個時間點（catch-up，避免落後時瘋狂補跑）
                $interval = max(1, (int) $task['interval_sec']);
                $next = $nextRun + $interval;
                while ($next <= $nowTs) {
                    $next += $interval;
                }
                $this->store->updateTask($task['id'], [
                    'next_run_at' => $next,
                    'last_fired_at' => $fireAt,
                ]);
            } else {
                // 一次性任務：觸發後不再排定，狀態移交給佇列/worker，這裡標記為已派發
                $this->store->updateTask($task['id'], [
                    'status' => 'DISPATCHED', // 已交付佇列，不再被 scheduler 掃描
                    'next_run_at' => 0,
                    'last_fired_at' => $fireAt,
                ]);
            }
        }

        return $fired;
    }
}
