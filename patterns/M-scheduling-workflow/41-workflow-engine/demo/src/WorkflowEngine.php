<?php
declare(strict_types=1);

/**
 * 工作流引擎（教學版，單機 + 步進，模擬 Temporal / Airflow 核心邏輯）
 * ---------------------------------------------------------------------------
 * 真實系統（Temporal / Airflow / Step Functions）：有持久化叢集、多 worker、
 * task queue、心跳、計時器服務、可水平擴展的歷史服務。
 * 這裡用「一個 workflow 實例 + 一份 event log（事件溯源）」+ 手動 tick 步進，
 * 但以下邏輯都是真的、可實際執行，也正是面試考點：
 *
 *   1. DAG 排程與相依：step 有 deps（前置 step），deps 全完成才 ready；
 *      引擎每次 tick 挑出所有 ready 的 step 執行 → 無環、依賴拓樸推進。
 *   2. durable execution / 事件溯源：每個 step 的開始/完成/失敗/補償都寫入 event log；
 *      引擎的「目前狀態」永遠由重放 event log 推導（state = fold(events)），
 *      因此崩潰後可從 log 續跑、且重放具確定性。
 *   3. 重放不重跑：replay() 只讀 event log 重建狀態，不重新執行任何 step 副作用，
 *      已完成的 step 直接拿 log 裡的結果 → exactly-once 效果。
 *   4. 重試 + 指數退避：step 失敗 → 記 attempt，退避到上限前自動重試；超過上限標記 FAILED。
 *   5. saga 補償：某 step 最終失敗 → 依「已完成 step 的反向順序」執行補償動作。
 *   6. 人工審批 / 等待外部事件：approval 型 step 執行到會 BLOCKED，
 *      直到外部 signal() 放行才繼續推進。
 *
 * 所有狀態都落在 data/ 下的 event log（events.ndjson）與定義檔（workflow.json），
 * 寫入用 flock 保護，支援多請求併發。
 */
final class WorkflowEngine
{
    // step 執行型別
    public const TYPE_TASK     = 'task';      // 一般任務（可能失敗 → 重試 → saga）
    public const TYPE_APPROVAL = 'approval';  // 人工審批節點（等 signal）

    // step 狀態
    public const PENDING      = 'PENDING';      // 尚未 ready（deps 未完成）
    public const RUNNING_DONE = 'DONE';         // 已完成
    public const FAILED       = 'FAILED';       // 重試上限後仍失敗
    public const BLOCKED      = 'BLOCKED';      // 審批中，等 signal
    public const COMPENSATED  = 'COMPENSATED';  // 已被 saga 補償

    // event log 事件型別
    public const EV_DEFINED      = 'WORKFLOW_DEFINED';
    public const EV_STEP_DONE    = 'STEP_COMPLETED';
    public const EV_STEP_RETRY   = 'STEP_RETRY';       // 一次失敗、將重試
    public const EV_STEP_FAILED  = 'STEP_FAILED';      // 重試上限後標記失敗
    public const EV_STEP_BLOCKED = 'STEP_BLOCKED';     // 審批節點進入等待
    public const EV_APPROVED     = 'STEP_APPROVED';    // 收到 signal 放行
    public const EV_COMPENSATED  = 'STEP_COMPENSATED'; // saga 補償一個 step
    public const EV_WF_FAILED    = 'WORKFLOW_FAILED';  // saga 補償完成、流程終止

    private const MAX_ATTEMPTS = 3; // 重試上限（含首次嘗試）

    private string $defFile;
    private string $logFile;
    private string $failFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $dir = rtrim($dataDir, '/\\');
        $this->defFile  = $dir . '/workflow.json';
        $this->logFile  = $dir . '/events.ndjson';   // 事件溯源 log
        $this->failFile = $dir . '/inject_fail.json'; // 被注入失敗的 step 清單
    }

    // ===================================================================
    // 定義：載入一個範例工作流 DAG
    // ===================================================================

    /**
     * 定義工作流。每個 step：
     *   id, name, type(task|approval), deps(前置 step id 清單),
     *   compensate(補償動作描述，task 型才有意義)
     * 定義會：清掉舊 log、寫定義檔、寫一筆 WORKFLOW_DEFINED 事件。
     */
    public function define(array $steps): void
    {
        // 驗證為合法 DAG（無環、deps 都存在）
        $this->assertDag($steps);

        $def = ['steps' => array_values($steps), 'created_at' => gmdate('c')];
        file_put_contents(
            $this->defFile,
            (string) json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        // 重置 event log 與注入失敗
        file_put_contents($this->logFile, '', LOCK_EX);
        file_put_contents($this->failFile, '[]', LOCK_EX);

        $this->appendEvent([
            'type'  => self::EV_DEFINED,
            'steps' => array_map(static fn($s) => $s['id'], $steps),
        ]);
    }

    /** 載入一個內建範例 DAG：訂單履約流程（含審批節點、可補償的 task） */
    public function loadSample(): void
    {
        // DAG：
        //   reserve(預留庫存) ─┐
        //                       ├─▶ approve(風控審批) ─▶ pay(扣款) ─▶ ship(出貨) ─▶ notify(通知)
        //   charge_prep(備援) ─┘
        // 反向補償順序：notify ← ship ← pay ← approve ← {reserve, charge_prep}
        $this->define([
            ['id' => 'reserve',  'name' => '預留庫存',   'type' => self::TYPE_TASK,
             'deps' => [],                       'compensate' => '釋放預留庫存'],
            ['id' => 'charge_prep', 'name' => '建立付款單', 'type' => self::TYPE_TASK,
             'deps' => [],                       'compensate' => '作廢付款單'],
            ['id' => 'approve',  'name' => '風控人工審批', 'type' => self::TYPE_APPROVAL,
             'deps' => ['reserve', 'charge_prep'], 'compensate' => ''],
            ['id' => 'pay',      'name' => '扣款',       'type' => self::TYPE_TASK,
             'deps' => ['approve'],              'compensate' => '退款'],
            ['id' => 'ship',     'name' => '出貨',       'type' => self::TYPE_TASK,
             'deps' => ['pay'],                  'compensate' => '取消出貨單'],
            ['id' => 'notify',   'name' => '通知客戶',   'type' => self::TYPE_TASK,
             'deps' => ['ship'],                 'compensate' => '寄送取消通知'],
        ]);
    }

    // ===================================================================
    // tick：排程一步 —— 挑 ready step 執行，推進 DAG
    // ===================================================================

    /**
     * 執行一個排程步。流程：
     *   1. 由 event log 重放出目前狀態（durable execution 的核心）
     *   2. 若已 BLOCKED（審批中）或已終止 → 不做事
     *   3. 找出所有 ready 的 step（deps 全 DONE 且本身 PENDING）
     *   4. 對每個 ready step 執行：
     *        - approval 型 → 進入 BLOCKED，等 signal
     *        - task 型     → 嘗試執行；被注入失敗則 retry（退避），超上限則觸發 saga 補償
     *   5. 把產生的事件寫入 log（持久化）
     *
     * @return array 本次 tick 的摘要（ran / blocked / failed / 已寫入的事件）
     */
    public function tick(): array
    {
        $def   = $this->readDef();
        $state = $this->replay(); // 由 event log 推導目前狀態
        $events = [];

        if ($state['terminal']) {
            return $this->summary('已終止（' . $state['status'] . '），不再推進', $state, []);
        }

        // 審批中：整個流程暫停，等 signal
        foreach ($state['steps'] as $sid => $st) {
            if ($st['status'] === self::BLOCKED) {
                return $this->summary("等待審批：$sid（請對該 step 送 signal 放行）", $state, []);
            }
        }

        $ready = $this->readySteps($def, $state);
        if (!$ready) {
            return $this->summary('沒有 ready 的 step（可能已全部完成或在等待）', $state, []);
        }

        $injected = $this->readInjectedFails();

        foreach ($ready as $step) {
            $sid = $step['id'];

            // --- 審批節點：進入 BLOCKED，停下等 signal ---
            if (($step['type'] ?? self::TYPE_TASK) === self::TYPE_APPROVAL) {
                $ev = $this->appendEvent(['type' => self::EV_STEP_BLOCKED, 'step' => $sid]);
                $events[] = $ev;
                // 審批會阻塞後續，本 tick 不再處理其他 ready step
                break;
            }

            // --- task 節點：嘗試執行（可能被注入失敗 → 重試 → saga） ---
            $attempt = ($state['attempts'][$sid] ?? 0) + 1;
            $willFail = in_array($sid, $injected, true);

            if (!$willFail) {
                // 成功：寫 STEP_COMPLETED，附帶確定性結果（重放時直接拿，不重跑）
                $ev = $this->appendEvent([
                    'type'    => self::EV_STEP_DONE,
                    'step'    => $sid,
                    'attempt' => $attempt,
                    'result'  => $this->deterministicResult($sid, $attempt),
                ]);
                $events[] = $ev;
                $state = $this->applyEvent($state, $ev); // 即時套用，讓同 tick 後續 ready 判斷正確
                continue;
            }

            // 失敗：還在重試上限內 → 記一次 retry（指數退避）
            if ($attempt < self::MAX_ATTEMPTS) {
                $backoff = $this->backoffMs($attempt); // 100, 200, 400...
                $ev = $this->appendEvent([
                    'type'       => self::EV_STEP_RETRY,
                    'step'       => $sid,
                    'attempt'    => $attempt,
                    'backoff_ms' => $backoff,
                ]);
                $events[] = $ev;
                $state = $this->applyEvent($state, $ev);
                // 重試需要下一個 tick（模擬退避等待）→ 本 step 本 tick 不再前進
                continue;
            }

            // 超過上限 → 標記 FAILED → 觸發 saga 補償（反向順序）
            $failEv = $this->appendEvent([
                'type'    => self::EV_STEP_FAILED,
                'step'    => $sid,
                'attempt' => $attempt,
            ]);
            $events[] = $failEv;
            $state = $this->applyEvent($state, $failEv);

            $compEvents = $this->runSaga($def, $state, $sid);
            foreach ($compEvents as $ce) {
                $events[] = $ce;
                $state = $this->applyEvent($state, $ce);
            }
            // saga 後流程終止，本 tick 結束
            break;
        }

        $state = $this->replay(); // 重新由 log 推導，回傳權威狀態
        return $this->summary('tick 完成', $state, $events);
    }

    /**
     * saga 補償：某 step 最終失敗時，對「已完成的 step」依反向順序執行補償動作。
     * 反向順序 = event log 中 STEP_COMPLETED 出現順序的倒序。
     * @return list<array> 補償事件 + WORKFLOW_FAILED 事件
     */
    private function runSaga(array $def, array $state, string $failedStep): array
    {
        $compMap = [];
        foreach ($def['steps'] as $s) {
            $compMap[$s['id']] = $s['compensate'] ?? '';
        }

        // 依完成順序倒序補償
        $completedOrder = $state['completedOrder']; // 已完成 step 的時間序
        $events = [];
        foreach (array_reverse($completedOrder) as $sid) {
            $action = $compMap[$sid] ?? '';
            $events[] = $this->appendEvent([
                'type'   => self::EV_COMPENSATED,
                'step'   => $sid,
                'action' => $action !== '' ? $action : '(無補償動作)',
            ]);
        }
        $events[] = $this->appendEvent([
            'type'   => self::EV_WF_FAILED,
            'reason' => "step '$failedStep' 重試 " . self::MAX_ATTEMPTS . " 次仍失敗，已 saga 補償",
        ]);
        return $events;
    }

    // ===================================================================
    // signal：人工審批放行（外部事件）
    // ===================================================================

    /**
     * 對某審批 step 送 signal 放行。
     * 該 step 必須目前處於 BLOCKED；放行後寫 STEP_APPROVED + STEP_COMPLETED，
     * 流程可在下一個 tick 繼續推進。
     */
    public function signal(string $stepId): array
    {
        $state = $this->replay();
        $st = $state['steps'][$stepId] ?? null;
        if ($st === null) {
            return ['ok' => false, 'error' => "step 不存在：$stepId"];
        }
        if ($st['status'] !== self::BLOCKED) {
            return ['ok' => false, 'error' => "step '$stepId' 目前不是審批等待中（狀態：{$st['status']}）"];
        }

        $this->appendEvent(['type' => self::EV_APPROVED, 'step' => $stepId]);
        $this->appendEvent([
            'type'    => self::EV_STEP_DONE,
            'step'    => $stepId,
            'attempt' => 1,
            'result'  => 'approved-by-human',
        ]);

        return ['ok' => true, 'approved' => $stepId, 'state' => $this->snapshot($this->replay())];
    }

    // ===================================================================
    // 注入失敗 / 重置
    // ===================================================================

    /** 把某 step 標記為「執行時會失敗」，用來示範重試 + saga 補償 */
    public function injectFail(string $stepId): array
    {
        $list = $this->readInjectedFails();
        if (!in_array($stepId, $list, true)) {
            $list[] = $stepId;
        }
        file_put_contents(
            $this->failFile,
            (string) json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        return ['ok' => true, 'inject_fail' => $list];
    }

    /** 清空 event log（保留定義）→ 用來示範「重放不重跑」 */
    public function resetRun(): void
    {
        file_put_contents($this->logFile, '', LOCK_EX);
        $this->appendEvent([
            'type'  => self::EV_DEFINED,
            'steps' => array_map(static fn($s) => $s['id'], $this->readDef()['steps']),
        ]);
    }

    // ===================================================================
    // durable execution 核心：replay —— 由 event log 推導狀態
    // ===================================================================

    /**
     * 重放整份 event log，把狀態 fold 出來。
     * 關鍵：這裡「不執行任何 step 副作用」，只讀 log 重建。
     * 因此崩潰後重啟，呼叫 replay() 即可拿回完整進度，已完成 step 不重跑。
     *
     * @return array{
     *   steps: array<string,array{status:string}>,
     *   attempts: array<string,int>,
     *   completedOrder: list<string>,
     *   terminal: bool, status: string, eventCount: int
     * }
     */
    public function replay(): array
    {
        $def = $this->readDef();
        $state = [
            'steps'          => [],
            'attempts'       => [],
            'completedOrder' => [],
            'terminal'       => false,
            'status'         => 'RUNNING',
            'eventCount'     => 0,
            'replayedNoRerun'=> 0, // 重放時「直接拿 log 結果、未重跑」的 step 數
        ];
        foreach ($def['steps'] as $s) {
            $state['steps'][$s['id']] = ['status' => self::PENDING];
        }

        foreach ($this->readEvents() as $ev) {
            $state = $this->applyEvent($state, $ev);
        }
        return $state;
    }

    /** 把單一事件套用到狀態上（事件溯源的 reducer） */
    private function applyEvent(array $state, array $ev): array
    {
        $state['eventCount']++;
        $type = $ev['type'] ?? '';
        $sid  = $ev['step'] ?? null;

        switch ($type) {
            case self::EV_STEP_DONE:
                if ($sid !== null) {
                    $already = ($state['steps'][$sid]['status'] ?? '') === self::RUNNING_DONE;
                    $state['steps'][$sid] = ['status' => self::RUNNING_DONE, 'result' => $ev['result'] ?? null];
                    if (!$already) {
                        $state['completedOrder'][] = $sid;
                        // 這筆完成是「從 log 拿回」的：重放時計一次「不重跑」
                        $state['replayedNoRerun']++;
                    }
                }
                break;
            case self::EV_STEP_RETRY:
                if ($sid !== null) {
                    $state['attempts'][$sid] = (int) ($ev['attempt'] ?? ($state['attempts'][$sid] ?? 0) + 1);
                }
                break;
            case self::EV_STEP_FAILED:
                if ($sid !== null) {
                    $state['steps'][$sid] = ['status' => self::FAILED];
                    $state['attempts'][$sid] = (int) ($ev['attempt'] ?? 0);
                }
                break;
            case self::EV_STEP_BLOCKED:
                if ($sid !== null) {
                    $state['steps'][$sid] = ['status' => self::BLOCKED];
                }
                break;
            case self::EV_APPROVED:
                // 放行本身不改完成狀態，後續會有 STEP_COMPLETED
                break;
            case self::EV_COMPENSATED:
                if ($sid !== null) {
                    $state['steps'][$sid] = ['status' => self::COMPENSATED];
                }
                break;
            case self::EV_WF_FAILED:
                $state['terminal'] = true;
                $state['status']   = 'FAILED';
                break;
            case self::EV_DEFINED:
            default:
                break;
        }

        // 全部 task/approval step 都 DONE → 流程成功終止
        if (!$state['terminal']) {
            $allDone = true;
            foreach ($state['steps'] as $st) {
                if ($st['status'] !== self::RUNNING_DONE) {
                    $allDone = false;
                    break;
                }
            }
            if ($allDone && $state['steps'] !== []) {
                $state['terminal'] = true;
                $state['status']   = 'COMPLETED';
            }
        }
        return $state;
    }

    // ===================================================================
    // DAG 排程：找 ready step
    // ===================================================================

    /** ready = 本身 PENDING 且所有 deps 都已 DONE */
    private function readySteps(array $def, array $state): array
    {
        $ready = [];
        foreach ($def['steps'] as $s) {
            $sid = $s['id'];
            if (($state['steps'][$sid]['status'] ?? self::PENDING) !== self::PENDING) {
                continue;
            }
            $depsDone = true;
            foreach ($s['deps'] ?? [] as $dep) {
                if (($state['steps'][$dep]['status'] ?? '') !== self::RUNNING_DONE) {
                    $depsDone = false;
                    break;
                }
            }
            if ($depsDone) {
                $ready[] = $s;
            }
        }
        return $ready;
    }

    // ===================================================================
    // 對外狀態快照
    // ===================================================================

    public function state(): array
    {
        return $this->snapshot($this->replay());
    }

    /** 把內部狀態整理成易讀快照（含 DAG、event log、ready 清單） */
    private function snapshot(array $state): array
    {
        $def = $this->readDef();
        $stepView = [];
        foreach ($def['steps'] as $s) {
            $sid = $s['id'];
            $stepView[] = [
                'id'      => $sid,
                'name'    => $s['name'] ?? $sid,
                'type'    => $s['type'] ?? self::TYPE_TASK,
                'deps'    => $s['deps'] ?? [],
                'status'  => $state['steps'][$sid]['status'] ?? self::PENDING,
                'attempts'=> $state['attempts'][$sid] ?? 0,
            ];
        }
        $ready = array_map(static fn($s) => $s['id'], $this->readySteps($def, $state));

        return [
            'status'          => $state['status'],
            'terminal'        => $state['terminal'],
            'steps'           => $stepView,
            'ready'           => $ready,
            'completedOrder'  => $state['completedOrder'],
            'eventCount'      => $state['eventCount'],
            'replayedNoRerun' => $state['replayedNoRerun'],
            'events'          => $this->readEvents(),
            'injectedFails'   => $this->readInjectedFails(),
        ];
    }

    private function summary(string $msg, array $state, array $events): array
    {
        return [
            'message' => $msg,
            'newEvents' => $events,
            'state' => $this->snapshot($state),
        ];
    }

    // ===================================================================
    // event log I/O（NDJSON，append-only，flock 保護）
    // ===================================================================

    private function appendEvent(array $event): array
    {
        $event['seq'] = $this->nextSeq();
        $event['ts']  = gmdate('c');
        $fp = fopen($this->logFile, 'c+');
        if ($fp === false) {
            throw new RuntimeException('無法開啟 event log');
        }
        flock($fp, LOCK_EX);
        fseek($fp, 0, SEEK_END);
        fwrite($fp, json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $event;
    }

    private function nextSeq(): int
    {
        return count($this->readEvents()) + 1;
    }

    /** @return list<array> 依寫入順序的所有事件 */
    public function readEvents(): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }
        $out = [];
        $fp = fopen($this->logFile, 'r');
        if ($fp === false) {
            return [];
        }
        flock($fp, LOCK_SH);
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $rec = json_decode($line, true);
            if (is_array($rec)) {
                $out[] = $rec;
            }
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return $out;
    }

    private function readDef(): array
    {
        if (!file_exists($this->defFile)) {
            return ['steps' => []];
        }
        $arr = json_decode((string) file_get_contents($this->defFile), true);
        return is_array($arr) && isset($arr['steps']) ? $arr : ['steps' => []];
    }

    /** @return list<string> 被注入失敗的 step id */
    private function readInjectedFails(): array
    {
        if (!file_exists($this->failFile)) {
            return [];
        }
        $arr = json_decode((string) file_get_contents($this->failFile), true);
        return is_array($arr) ? array_values(array_filter($arr, 'is_string')) : [];
    }

    public function hasDefinition(): bool
    {
        return $this->readDef()['steps'] !== [];
    }

    // ===================================================================
    // 輔助：確定性結果 / 退避 / DAG 驗證
    // ===================================================================

    /** 確定性結果：同 step + attempt 永遠得到同一個值（重放可重現） */
    private function deterministicResult(string $sid, int $attempt): string
    {
        return substr(hash('sha256', $sid . ':' . $attempt), 0, 12);
    }

    /** 指數退避（毫秒）：100, 200, 400, ... */
    private function backoffMs(int $attempt): int
    {
        return 100 * (2 ** ($attempt - 1));
    }

    /** 驗證為合法 DAG：deps 必須存在、且無環（拓樸排序成功） */
    private function assertDag(array $steps): void
    {
        $ids = [];
        foreach ($steps as $s) {
            if (!isset($s['id']) || !is_string($s['id']) || $s['id'] === '') {
                throw new InvalidArgumentException('每個 step 必須有非空 id');
            }
            $ids[$s['id']] = true;
        }
        // deps 必須存在
        $adj = [];
        $indeg = [];
        foreach ($steps as $s) {
            $indeg[$s['id']] = $indeg[$s['id']] ?? 0;
            foreach ($s['deps'] ?? [] as $dep) {
                if (!isset($ids[$dep])) {
                    throw new InvalidArgumentException("step '{$s['id']}' 的相依 '$dep' 不存在");
                }
                $adj[$dep][] = $s['id'];
                $indeg[$s['id']] = ($indeg[$s['id']] ?? 0) + 1;
            }
        }
        // Kahn 拓樸排序檢測環
        $queue = [];
        foreach ($indeg as $id => $d) {
            if ($d === 0) {
                $queue[] = $id;
            }
        }
        $visited = 0;
        while ($queue) {
            $cur = array_shift($queue);
            $visited++;
            foreach ($adj[$cur] ?? [] as $next) {
                $indeg[$next]--;
                if ($indeg[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }
        if ($visited !== count($ids)) {
            throw new InvalidArgumentException('工作流定義含環，不是合法 DAG');
        }
    }
}
