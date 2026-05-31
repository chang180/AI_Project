<?php
declare(strict_types=1);

require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/InferenceModel.php';

/**
 * 批次排程器 —— 本題真正考點：continuous batching（連續批次）
 * ============================================================
 * 對應真實元件：vLLM / TGI 的排程迴圈。
 *
 * 核心語意：
 *   - 維護一個「執行中批次」（running），容量上限 = maxBatch（受 KV cache 顯存約束）。
 *   - 每呼叫一次 step()：
 *       1) 先「填補」—— 只要批次有空槽且佇列（依優先級排序）非空，就立刻把新請求
 *          拉進批次（先做一次 prefill），而不是等整批做完。  ← continuous batching 精髓
 *       2) 再「推進」—— 為批次內每個 DECODING 請求生成 1 個 token；
 *          達到計畫長度者標記 DONE 並當場讓出槽位。
 *   - 統計每個 step 的「GPU 槽位利用率」（佔用槽 / 總槽），累加成平均利用率。
 *
 * 對照組：staticBaselineUtilisation() 計算「靜態批次」在同一批請求下的理論利用率，
 *         凸顯靜態批次因「等最慢的請求」而大量空轉。
 */
final class BatchScheduler
{
    /** @var Request[] 等待佇列 */
    private array $queue = [];
    /** @var array<int,?Request> 執行中批次，索引即 GPU 槽位編號（null=空槽） */
    private array $slots = [];
    /** @var Request[] 已完成 */
    private array $done = [];

    private int $step = 0;
    private int $seq = 0;

    // 利用率統計
    private int $busySlotSteps = 0;   // 累計「被佔用的槽 × step」
    private int $totalSlotSteps = 0;  // 累計「總槽位 × step」
    /** @var array<int,array> 每個 step 的快照，供前端逐步呈現 */
    private array $history = [];

    public function __construct(
        private readonly int $maxBatch,
        private readonly InferenceModel $model
    ) {
        // 初始化空槽位
        for ($i = 0; $i < $this->maxBatch; $i++) {
            $this->slots[$i] = null;
        }
    }

    /** 提交一個生成請求進佇列 */
    public function submit(string $tenant, int $priority, string $prompt, int $maxTokens): Request
    {
        $planned = $this->model->plannedTokens($prompt, $maxTokens);
        $id = 'req-' . (++$this->seq);
        $req = new Request($id, $tenant, $priority, $prompt, $planned, $this->step);
        $this->queue[] = $req;
        $this->sortQueue();
        return $req;
    }

    /** 佇列依優先級（小=高）排序，同優先級先到先服務（用 enqueueStep + 序） */
    private function sortQueue(): void
    {
        usort($this->queue, static function (Request $a, Request $b): int {
            return [$a->priority, $a->enqueueStep, $a->id] <=> [$b->priority, $b->enqueueStep, $b->id];
        });
    }

    /**
     * 推進一個 step：先填補空槽，再為批次推進一個 token。
     * 回傳本 step 的快照。
     */
    public function step(): array
    {
        $this->step++;
        $filledThisStep = [];

        // ---- 1) 連續批次的核心：有空槽就立刻從佇列拉新請求填補 ----
        foreach ($this->slots as $i => $slot) {
            if ($slot === null && !empty($this->queue)) {
                $req = array_shift($this->queue);
                // prefill：本 demo 把整段 prompt 的處理視為這一個 step 的「進場」
                $req->state = Request::PREFILL;
                $req->startStep = $this->step;
                $this->slots[$i] = $req;
                $filledThisStep[] = $req->id;
            }
        }

        // ---- 2) 為批次內每個請求推進 1 個 token ----
        $tokensThisStep = [];
        foreach ($this->slots as $i => $slot) {
            if ($slot === null) {
                continue;
            }
            // 第一次進場是 prefill，下一步起轉 DECODING
            if ($slot->state === Request::PREFILL) {
                $slot->state = Request::DECODING;
            }
            // 生成一個 token
            $tok = $this->model->nextToken($slot->prompt, $slot->generated);
            $slot->tokens[] = $tok;
            $slot->generated++;
            $tokensThisStep[$slot->id] = $tok;

            // 達到計畫長度 → 完成，當場讓出槽位（不必等整批）
            if ($slot->generated >= $slot->plannedTokens) {
                $slot->state = Request::DONE;
                $slot->finishStep = $this->step;
                $this->done[] = $slot;
                $this->slots[$i] = null;
            }
        }

        // ---- 3) 統計 GPU 槽位利用率 ----
        // 本 step 真正佔用 GPU 的槽 = 這一步有生成 token 的請求數
        // （含本 step 完成才讓出的槽，因為它在生成那一刻仍佔著槽）
        $busyDuringStep = count($tokensThisStep);

        $this->busySlotSteps += $busyDuringStep;
        $this->totalSlotSteps += $this->maxBatch;

        $snap = [
            'step'        => $this->step,
            'filled'      => $filledThisStep,
            'tokens'      => $tokensThisStep,
            'slots'       => $this->slotsSnapshot(),
            'queueLen'    => count($this->queue),
            'doneCount'   => count($this->done),
            'busy'        => $busyDuringStep,
            'capacity'    => $this->maxBatch,
            'utilisation' => $this->maxBatch > 0
                ? round($busyDuringStep / $this->maxBatch * 100, 1)
                : 0.0,
        ];
        $this->history[] = $snap;
        return $snap;
    }

    /** 是否還有工作（佇列或批次尚未清空） */
    public function hasWork(): bool
    {
        if (!empty($this->queue)) {
            return true;
        }
        foreach ($this->slots as $slot) {
            if ($slot !== null) {
                return true;
            }
        }
        return false;
    }

    /** 一路推進到全部完成，回傳所有 step 快照 */
    public function runToCompletion(int $safety = 1000): array
    {
        $steps = [];
        while ($this->hasWork() && $safety-- > 0) {
            $steps[] = $this->step();
        }
        return $steps;
    }

    private function slotsSnapshot(): array
    {
        $out = [];
        foreach ($this->slots as $i => $slot) {
            $out[$i] = $slot === null
                ? ['empty' => true]
                : [
                    'id'        => $slot->id,
                    'tenant'    => $slot->tenant,
                    'priority'  => $slot->priority,
                    'state'     => $slot->state,
                    'generated' => $slot->generated,
                    'planned'   => $slot->plannedTokens,
                ];
        }
        return $out;
    }

    /** 連續批次的平均 GPU 槽位利用率（%） */
    public function continuousUtilisation(): float
    {
        return $this->totalSlotSteps > 0
            ? round($this->busySlotSteps / $this->totalSlotSteps * 100, 1)
            : 0.0;
    }

    /**
     * 對照組：靜態批次的理論平均利用率。
     * 靜態批次把請求切成「每 maxBatch 個一組」，每組要跑到「組內最長請求」的長度才釋放。
     * 短請求生完後在剩餘 step 都是空轉 → 利用率被拉低。
     */
    public function staticBaselineUtilisation(): float
    {
        // 收集所有請求（已完成 + 仍在批次 + 仍在佇列）的計畫長度
        $lengths = [];
        foreach ($this->done as $r) {
            $lengths[] = $r->plannedTokens + 1;   // +1 模擬 prefill step
        }
        foreach ($this->slots as $slot) {
            if ($slot !== null) {
                $lengths[] = $slot->plannedTokens + 1;
            }
        }
        foreach ($this->queue as $r) {
            $lengths[] = $r->plannedTokens + 1;
        }
        if (empty($lengths)) {
            return 0.0;
        }

        $busy = 0;
        $total = 0;
        $groups = array_chunk($lengths, $this->maxBatch);
        foreach ($groups as $g) {
            $groupSteps = max($g);                 // 整組要跑到最長那個
            $total += $groupSteps * $this->maxBatch;
            $busy  += array_sum($g);               // 每個請求只在自己長度內忙碌
        }
        return $total > 0 ? round($busy / $total * 100, 1) : 0.0;
    }

    public function stats(): array
    {
        return [
            'steps'                => $this->step,
            'doneCount'            => count($this->done),
            'queueLen'             => count($this->queue),
            'maxBatch'             => $this->maxBatch,
            'continuousUtil'       => $this->continuousUtilisation(),
            'staticBaselineUtil'   => $this->staticBaselineUtilisation(),
        ];
    }

    public function history(): array
    {
        return $this->history;
    }

    /** @return Request[] */
    public function doneRequests(): array
    {
        return $this->done;
    }
}
