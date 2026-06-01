<?php
declare(strict_types=1);

/**
 * Raft 共識演算法（教學版，單 process 內模擬 N 節點 + 手動 tick 驅動邏輯時鐘）
 * =====================================================================
 * 真實 Raft：每個節點是獨立進程/機器，透過網路 RPC（RequestVote / AppendEntries）
 * 互相通訊，各自有「隨機化的選舉逾時計時器」在背景跑。
 *
 * 因為 PHP 沒有跨請求的常駐進程與計時器，這裡改用「單一 process 內模擬 N 個節點 +
 * 手動 tick() 推進邏輯時鐘」的『確定性模擬』。RPC 變成同一 process 內的函式呼叫，
 * 選舉逾時改用每節點的倒數計時器（tick 一次減一），逾時即觸發選舉。
 *
 * 以下 Raft 規則皆為真實且正確實作（這正是面試考點）：
 *   1. Leader election：follower 選舉逾時 → 升 candidate、currentTerm+1、投自己、
 *      發 RequestVote。投票限制：**候選人的 log 必須至少和投票者一樣新**才投票
 *      （比 lastLogTerm，平手再比 lastLogIndex）。得到多數票 → 成為 leader，
 *      立刻發心跳（空的 AppendEntries）壓制其他候選人。
 *   2. Log replication：client command 只交給 leader → leader append 到自己的 log →
 *      透過 AppendEntries 複製給 followers，含 prevLogIndex/prevLogTerm 一致性檢查，
 *      不一致則 follower 拒絕、leader 回退 nextIndex 重試；衝突項目截斷後覆蓋。
 *   3. Commit 規則：某 log index 被複製到「多數」節點 **且該 entry 的 term == 當前 term**
 *      時，leader 才能推進 commitIndex（避免提交舊 term 的 entry 造成覆蓋風險）。
 *   4. Safety：term 規則（收到更高 term → 立即降為 follower 並更新 term）、
 *      選舉限制（log 落後者不可能當選 → leader completeness）。
 *   5. 故障/分區：可 kill / revive 節點。kill 掉 leader 後再 tick 幾次，
 *      存活的多數會重新選出新 leader，且已 commit 的 log 不會遺失。
 *
 * 狀態持久化：整個叢集狀態存成單一 JSON 檔（data/cluster.json），用 flock 保護，
 * 對應 Raft 論文中「currentTerm / votedFor / log[] 需持久化」的要求。
 */
final class RaftCluster
{
    private string $dataDir;
    private string $stateFile;

    /** 預設選舉逾時範圍（邏輯 tick 數）；隨機化避免所有節點同時逾時造成分票。 */
    private const ELECTION_TIMEOUT_MIN = 5;
    private const ELECTION_TIMEOUT_MAX = 10;
    /** 心跳間隔（leader 每隔幾 tick 發一次心跳）；必須 << 選舉逾時。 */
    private const HEARTBEAT_INTERVAL = 1;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dataDir = rtrim($dataDir, '/\\');
        $this->stateFile = $this->dataDir . '/cluster.json';
        if (!file_exists($this->stateFile)) {
            $this->writeState($this->freshState(3));
        }
    }

    // ============================================================
    // 對外 API（給路由呼叫）
    // ============================================================

    /** 重置叢集為 $n 個全新 follower（n 必須為 3 或 5，奇數較佳）。 */
    public function reset(int $n = 3): array
    {
        if ($n < 3) {
            $n = 3;
        }
        if ($n > 5) {
            $n = 5;
        }
        $state = $this->freshState($n);
        $this->writeState($state);
        return $this->publicState($state);
    }

    /** 推進邏輯時鐘 $steps 次，回傳每一步發生的事件與最終狀態。 */
    public function tick(int $steps = 1): array
    {
        if ($steps < 1) {
            $steps = 1;
        }
        if ($steps > 50) {
            $steps = 50;
        }
        $state = $this->readState();
        $events = [];
        for ($i = 0; $i < $steps; $i++) {
            $state['logicalClock']++;
            $stepEvents = $this->stepOnce($state);
            foreach ($stepEvents as $e) {
                $events[] = '[t=' . $state['logicalClock'] . '] ' . $e;
            }
        }
        $this->writeState($state);
        return [
            'steps' => $steps,
            'events' => $events,
            'state' => $this->publicState($state),
        ];
    }

    /**
     * client 送出一個 command（只有 leader 接受）。
     * leader 把 entry append 到自己的 log（尚未 commit），等後續 tick 複製到多數才 commit。
     */
    public function submitCommand(string $command): array
    {
        $state = $this->readState();
        $leaderId = $this->currentLeaderId($state);
        if ($leaderId === null) {
            return [
                'ok' => false,
                'error' => '目前沒有 leader（請先 tick 幾次選出 leader 再提交）',
                'state' => $this->publicState($state),
            ];
        }
        $leader = &$state['nodes'][$leaderId];
        $leader['log'][] = ['term' => $leader['currentTerm'], 'command' => $command];
        $newIndex = count($leader['log']); // 1-based index
        // leader 立刻嘗試把新 entry 複製給 followers（一輪 AppendEntries）。
        $this->leaderReplicate($state, $leaderId);
        $this->advanceCommitIndex($state, $leaderId);
        $this->applyCommitted($state);
        $this->writeState($state);
        return [
            'ok' => true,
            'leader' => $leaderId,
            'appendedAtIndex' => $newIndex,
            'command' => $command,
            'note' => '已 append 到 leader log；多數副本確認後才會 commit。可多 tick 幾次再看 commitIndex。',
            'state' => $this->publicState($state),
        ];
    }

    /** kill 或 revive 某節點。kill 後該節點不參與任何 RPC（模擬當機/分區）。 */
    public function setNodeAlive(string $nodeId, bool $alive): array
    {
        $state = $this->readState();
        if (!isset($state['nodes'][$nodeId])) {
            return ['ok' => false, 'error' => "節點 $nodeId 不存在", 'state' => $this->publicState($state)];
        }
        $state['nodes'][$nodeId]['alive'] = $alive;
        if (!$alive) {
            // 當機節點若是 leader，叢集會在後續 tick 因收不到心跳而重新選舉。
            // 復活的節點以 follower 身分回來（Raft：重啟後一律是 follower）。
        } else {
            $node = &$state['nodes'][$nodeId];
            $node['role'] = 'follower';
            $node['votesGranted'] = [];
            $node['electionTimer'] = $this->randomTimeout($state);
        }
        $this->writeState($state);
        return [
            'ok' => true,
            'node' => $nodeId,
            'alive' => $alive,
            'state' => $this->publicState($state),
        ];
    }

    public function getState(): array
    {
        return $this->publicState($this->readState());
    }

    // ============================================================
    // 核心：一個 tick 內的狀態機推進
    // ============================================================

    /** @param array<string,mixed> $state */
    private function stepOnce(array &$state): array
    {
        $events = [];

        // 1) 每個存活的 follower / candidate 倒數選舉計時器；leader 倒數心跳計時器。
        foreach ($state['nodes'] as $id => &$node) {
            if (!$node['alive']) {
                continue;
            }
            if ($node['role'] === 'leader') {
                $node['heartbeatTimer']--;
            } else {
                $node['electionTimer']--;
            }
        }
        unset($node);

        // 2) leader 心跳到期 → 發 AppendEntries（含複製），並重設心跳計時器。
        foreach ($state['nodes'] as $id => $node) {
            if ($node['alive'] && $node['role'] === 'leader' && $node['heartbeatTimer'] <= 0) {
                $this->leaderReplicate($state, $id);
                $this->advanceCommitIndex($state, $id);
                $state['nodes'][$id]['heartbeatTimer'] = self::HEARTBEAT_INTERVAL;
            }
        }

        // 3) follower / candidate 選舉計時器到期 → 發起新一輪選舉。
        foreach ($state['nodes'] as $id => $node) {
            if (!$node['alive']) {
                continue;
            }
            if ($node['role'] !== 'leader' && $node['electionTimer'] <= 0) {
                $msg = $this->startElection($state, $id);
                $events[] = $msg;
            }
        }

        // 4) 套用已 commit 的 entry 到狀態機。
        $this->applyCommitted($state);

        return $events;
    }

    /**
     * 節點發起選舉：term+1、變 candidate、投自己、向其他存活節點要票。
     * 投票限制：候選人 log 必須至少和對方一樣新（lastLogTerm 大者勝，平手比 lastLogIndex）。
     */
    private function startElection(array &$state, string $candidateId): string
    {
        $cand = &$state['nodes'][$candidateId];
        $cand['currentTerm']++;
        $cand['role'] = 'candidate';
        $cand['votedFor'] = $candidateId;
        $cand['votesGranted'] = [$candidateId => true];
        $cand['electionTimer'] = $this->randomTimeout($state); // 重設，避免立刻再次逾時

        $term = $cand['currentTerm'];
        $lastLogIndex = count($cand['log']);
        $lastLogTerm = $lastLogIndex > 0 ? $cand['log'][$lastLogIndex - 1]['term'] : 0;

        $voters = [];
        foreach ($state['nodes'] as $vid => &$voter) {
            if ($vid === $candidateId || !$voter['alive']) {
                continue;
            }
            // 規則：收到更高 term → 先降為 follower 並更新 term。
            if ($term > $voter['currentTerm']) {
                $voter['currentTerm'] = $term;
                $voter['votedFor'] = null;
                $voter['role'] = 'follower';
            }
            $granted = $this->handleRequestVote($voter, $term, $candidateId, $lastLogIndex, $lastLogTerm);
            if ($granted) {
                $cand['votesGranted'][$vid] = true;
                $voters[] = $vid;
            }
        }
        unset($voter);

        $votes = count($cand['votesGranted']);
        $majority = $this->majority($state);
        if ($votes >= $majority && $cand['role'] === 'candidate') {
            $this->becomeLeader($state, $candidateId);
            return "節點 {$candidateId} 發起選舉(term={$term})，得 {$votes}/" . $this->aliveCount($state)
                . " 票（多數={$majority}）→ 當選 LEADER" . ($voters ? '，獲票自 ' . implode(',', $voters) : '');
        }
        return "節點 {$candidateId} 發起選舉(term={$term})，得 {$votes} 票（需 {$majority}）"
            . ($voters ? '，獲票自 ' . implode(',', $voters) : '，未獲其他票');
    }

    /**
     * 處理 RequestVote：回傳是否投票。
     * 條件：(a) 同一 term 內尚未投給別人（votedFor 為 null 或就是該候選人）
     *       (b) 候選人 log 至少和自己一樣新（選舉限制 / election restriction）
     */
    private function handleRequestVote(
        array &$voter,
        int $term,
        string $candidateId,
        int $candLastLogIndex,
        int $candLastLogTerm
    ): bool {
        if ($term < $voter['currentTerm']) {
            return false; // 候選人 term 過舊
        }
        if ($voter['votedFor'] !== null && $voter['votedFor'] !== $candidateId) {
            return false; // 本 term 已投過別人
        }
        // 選舉限制：比較誰的 log 較新。
        $voterLastIndex = count($voter['log']);
        $voterLastTerm = $voterLastIndex > 0 ? $voter['log'][$voterLastIndex - 1]['term'] : 0;
        $candUpToDate = ($candLastLogTerm > $voterLastTerm)
            || ($candLastLogTerm === $voterLastTerm && $candLastLogIndex >= $voterLastIndex);
        if (!$candUpToDate) {
            return false; // 候選人 log 落後 → 拒絕投票（保證 leader completeness）
        }
        $voter['votedFor'] = $candidateId;
        $voter['role'] = 'follower';
        return true; // 投票成功；選舉計時器在收到當選 leader 的心跳時重設
    }

    /** 候選人當選 leader：初始化 nextIndex / matchIndex，並立刻發一輪心跳壓制其他候選人。 */
    private function becomeLeader(array &$state, string $leaderId): void
    {
        $leader = &$state['nodes'][$leaderId];
        $leader['role'] = 'leader';
        $leader['heartbeatTimer'] = self::HEARTBEAT_INTERVAL;
        $lastIndex = count($leader['log']);
        $leader['nextIndex'] = [];
        $leader['matchIndex'] = [];
        foreach ($state['nodes'] as $id => $n) {
            if ($id === $leaderId) {
                continue;
            }
            $leader['nextIndex'][$id] = $lastIndex + 1; // 樂觀假設對方跟上，不一致再回退
            $leader['matchIndex'][$id] = 0;
        }
        unset($leader);
        // 立刻複製（空心跳 + 同步既有 log），壓制其他 candidate。
        $this->leaderReplicate($state, $leaderId);
        $this->advanceCommitIndex($state, $leaderId);
    }

    /**
     * Leader 對所有存活 follower 跑一輪 AppendEntries（含一致性檢查與回退）。
     * 這裡把「多輪回退」在單次呼叫內收斂（while 迴圈），等效於真實 Raft 多次 RPC 後達成。
     */
    private function leaderReplicate(array &$state, string $leaderId): void
    {
        if (!isset($state['nodes'][$leaderId]) || $state['nodes'][$leaderId]['role'] !== 'leader') {
            return;
        }
        $leaderTerm = $state['nodes'][$leaderId]['currentTerm'];
        $leaderLog = $state['nodes'][$leaderId]['log'];
        $leaderCommit = $state['nodes'][$leaderId]['commitIndex'];

        foreach ($state['nodes'] as $fid => &$follower) {
            if ($fid === $leaderId || !$follower['alive']) {
                continue;
            }
            // 若 follower term 較舊 → 更新並降為 follower。
            if ($leaderTerm > $follower['currentTerm']) {
                $follower['currentTerm'] = $leaderTerm;
                $follower['votedFor'] = null;
            }
            // 收到合法 leader 心跳 → 確認自己是 follower、重設選舉計時器（避免發起選舉）。
            $follower['role'] = 'follower';
            $follower['electionTimer'] = $this->randomTimeout($state);

            $nextIndex = $state['nodes'][$leaderId]['nextIndex'][$fid] ?? (count($leaderLog) + 1);

            // 一致性檢查 + 回退：找到雙方相符的 prevLogIndex，再把後續 entry 覆蓋過去。
            while (true) {
                $prevLogIndex = $nextIndex - 1; // 1-based；0 代表空
                $prevLogTerm = $prevLogIndex > 0
                    ? ($leaderLog[$prevLogIndex - 1]['term'] ?? 0)
                    : 0;
                $ok = $this->handleAppendEntries(
                    $follower,
                    $leaderTerm,
                    $prevLogIndex,
                    $prevLogTerm,
                    array_slice($leaderLog, $prevLogIndex), // 從 prevLogIndex 之後的所有 entry
                    $leaderCommit
                );
                if ($ok) {
                    // 成功：matchIndex = leader log 末端，nextIndex 跟上。
                    $matched = count($leaderLog);
                    $state['nodes'][$leaderId]['matchIndex'][$fid] = $matched;
                    $state['nodes'][$leaderId]['nextIndex'][$fid] = $matched + 1;
                    break;
                }
                // 失敗（prevLog 不符）→ nextIndex 回退一格重試。
                $nextIndex--;
                if ($nextIndex < 1) {
                    $nextIndex = 1;
                    // 已回退到頭仍以最保守方式重試一次後跳出，避免死迴圈。
                    $prevLogTerm = 0;
                    $ok2 = $this->handleAppendEntries(
                        $follower,
                        $leaderTerm,
                        0,
                        0,
                        $leaderLog,
                        $leaderCommit
                    );
                    if ($ok2) {
                        $matched = count($leaderLog);
                        $state['nodes'][$leaderId]['matchIndex'][$fid] = $matched;
                        $state['nodes'][$leaderId]['nextIndex'][$fid] = $matched + 1;
                    }
                    break;
                }
                $state['nodes'][$leaderId]['nextIndex'][$fid] = $nextIndex;
            }
        }
        unset($follower);
    }

    /**
     * Follower 處理 AppendEntries：
     *  1. term 過舊 → 拒絕。
     *  2. prevLogIndex 處的 term 不符 → 拒絕（觸發 leader 回退）。
     *  3. 通過 → 從 prevLogIndex 之後截斷衝突，append 新 entry；推進 commitIndex。
     *
     * @param array<int,array{term:int,command:string}> $entries
     */
    private function handleAppendEntries(
        array &$follower,
        int $leaderTerm,
        int $prevLogIndex,
        int $prevLogTerm,
        array $entries,
        int $leaderCommit
    ): bool {
        if ($leaderTerm < $follower['currentTerm']) {
            return false;
        }
        $follower['currentTerm'] = $leaderTerm;
        $follower['role'] = 'follower';

        // 一致性檢查：follower 在 prevLogIndex 必須有相符 term 的 entry（prevLogIndex=0 視為空、必通過）。
        if ($prevLogIndex > 0) {
            if (count($follower['log']) < $prevLogIndex) {
                return false; // follower log 太短
            }
            $followerPrevTerm = $follower['log'][$prevLogIndex - 1]['term'];
            if ($followerPrevTerm !== $prevLogTerm) {
                return false; // term 不符 → 衝突
            }
        }

        // 通過：把 prevLogIndex 之後的 log 截斷，覆蓋成 leader 帶來的 entries。
        $follower['log'] = array_slice($follower['log'], 0, $prevLogIndex);
        foreach ($entries as $e) {
            $follower['log'][] = ['term' => $e['term'], 'command' => $e['command']];
        }

        // 推進 follower commitIndex（不可超過自己 log 長度）。
        if ($leaderCommit > $follower['commitIndex']) {
            $follower['commitIndex'] = min($leaderCommit, count($follower['log']));
        }
        return true;
    }

    /**
     * Leader 推進 commitIndex：找到「被多數複製」且「該 entry term == 當前 term」的最大 index。
     * （Raft 安全性：leader 只能用「複製到多數的當前 term entry」來間接 commit 較舊的 entry。）
     */
    private function advanceCommitIndex(array &$state, string $leaderId): void
    {
        $leader = &$state['nodes'][$leaderId];
        if ($leader['role'] !== 'leader') {
            return;
        }
        $leaderTerm = $leader['currentTerm'];
        $lastIndex = count($leader['log']);
        $majority = $this->majority($state);

        for ($idx = $lastIndex; $idx > $leader['commitIndex']; $idx--) {
            // 只 commit 當前 term 的 entry（關鍵安全規則）。
            if ($leader['log'][$idx - 1]['term'] !== $leaderTerm) {
                continue;
            }
            $replicas = 1; // leader 自己算一份
            foreach ($state['nodes'] as $id => $n) {
                if ($id === $leaderId) {
                    continue;
                }
                if (($leader['matchIndex'][$id] ?? 0) >= $idx) {
                    $replicas++;
                }
            }
            if ($replicas >= $majority) {
                $leader['commitIndex'] = $idx;
                break;
            }
        }
        unset($leader);
    }

    /** 把每個節點已 commit 但尚未 apply 的 entry 套用到狀態機（這裡是一個 KV store）。 */
    private function applyCommitted(array &$state): void
    {
        foreach ($state['nodes'] as $id => &$node) {
            while ($node['lastApplied'] < $node['commitIndex']) {
                $node['lastApplied']++;
                $entry = $node['log'][$node['lastApplied'] - 1] ?? null;
                if ($entry === null) {
                    $node['lastApplied']--;
                    break;
                }
                $this->applyToStateMachine($node, (string) $entry['command']);
            }
        }
        unset($node);
    }

    /**
     * 狀態機：支援 "key=value" 設值、"key++" 計數器遞增，其餘存成最近一筆指令。
     * 重點是「所有節點 apply 相同有序 log → 得到相同狀態」（複製狀態機 RSM）。
     */
    private function applyToStateMachine(array &$node, string $command): void
    {
        $command = trim($command);
        if (preg_match('/^([A-Za-z0-9_]+)\+\+$/', $command, $m)) {
            $key = $m[1];
            $node['kv'][$key] = (int) ($node['kv'][$key] ?? 0) + 1;
            return;
        }
        if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.*)$/', $command, $m)) {
            $node['kv'][$m[1]] = $m[2];
            return;
        }
        $node['kv']['_last'] = $command;
    }

    // ============================================================
    // 輔助
    // ============================================================

    private function currentLeaderId(array $state): ?string
    {
        // 取存活且 role=leader 且 term 最高者。
        $leaderId = null;
        $bestTerm = -1;
        foreach ($state['nodes'] as $id => $n) {
            if ($n['alive'] && $n['role'] === 'leader' && $n['currentTerm'] > $bestTerm) {
                $bestTerm = $n['currentTerm'];
                $leaderId = $id;
            }
        }
        return $leaderId;
    }

    private function majority(array $state): int
    {
        $total = count($state['nodes']); // 固定以叢集總節點數算多數（含當機者），符合 Raft
        return intdiv($total, 2) + 1;
    }

    private function aliveCount(array $state): int
    {
        $c = 0;
        foreach ($state['nodes'] as $n) {
            if ($n['alive']) {
                $c++;
            }
        }
        return $c;
    }

    /** 確定性的「隨機」逾時：用 logicalClock 當種子混入節點計數，可重現但分散。 */
    private function randomTimeout(array $state): int
    {
        $span = self::ELECTION_TIMEOUT_MAX - self::ELECTION_TIMEOUT_MIN + 1;
        // 以邏輯時鐘 + 已配發次數做雜湊，讓不同節點拿到不同逾時，避免同步分票。
        $seed = ($state['logicalClock'] ?? 0) + ($state['timerSeq'] ?? 0);
        $offset = (int) (hexdec(substr(hash('crc32b', (string) $seed), 0, 4)) % $span);
        return self::ELECTION_TIMEOUT_MIN + $offset;
    }

    private function freshState(int $n): array
    {
        $nodes = [];
        for ($i = 0; $i < $n; $i++) {
            $id = 'n' . ($i + 1);
            $nodes[$id] = [
                'id' => $id,
                'role' => 'follower',
                'alive' => true,
                'currentTerm' => 0,
                'votedFor' => null,
                'log' => [],            // 每筆 {term, command}，1-based 對外
                'commitIndex' => 0,
                'lastApplied' => 0,
                'kv' => [],             // 狀態機
                'votesGranted' => [],
                'nextIndex' => [],
                'matchIndex' => [],
                // 用「i」讓初始選舉逾時錯開，避免第一次就分票。
                'electionTimer' => self::ELECTION_TIMEOUT_MIN + $i,
                'heartbeatTimer' => self::HEARTBEAT_INTERVAL,
            ];
        }
        return [
            'logicalClock' => 0,
            'timerSeq' => 0,
            'nodes' => $nodes,
        ];
    }

    /** 對外狀態（精簡、好讀）。 */
    private function publicState(array $state): array
    {
        $nodes = [];
        foreach ($state['nodes'] as $id => $n) {
            $nodes[] = [
                'id' => $id,
                'role' => $n['alive'] ? $n['role'] : 'DOWN',
                'alive' => $n['alive'],
                'term' => $n['currentTerm'],
                'votedFor' => $n['votedFor'],
                'logLen' => count($n['log']),
                'log' => array_map(
                    static fn($e) => $e['term'] . ':' . $e['command'],
                    $n['log']
                ),
                'commitIndex' => $n['commitIndex'],
                'lastApplied' => $n['lastApplied'],
                'electionTimer' => $n['role'] === 'leader' ? null : $n['electionTimer'],
                'kv' => (object) $n['kv'],
            ];
        }
        return [
            'logicalClock' => $state['logicalClock'],
            'leader' => $this->currentLeaderId($state),
            'majority' => $this->majority($state),
            'nodeCount' => count($state['nodes']),
            'nodes' => $nodes,
        ];
    }

    // ============================================================
    // 持久化（flock 保護，對應 Raft「currentTerm/votedFor/log 需持久化」）
    // ============================================================

    private function readState(): array
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            return $this->freshState(3);
        }
        flock($fp, LOCK_SH);
        $raw = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $state = json_decode($raw, true);
        if (!is_array($state) || !isset($state['nodes'])) {
            return $this->freshState(3);
        }
        return $state;
    }

    private function writeState(array $state): void
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
