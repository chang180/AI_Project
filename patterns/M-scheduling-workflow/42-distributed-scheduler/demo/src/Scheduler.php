<?php
declare(strict_types=1);

/**
 * 分散式排程器 / Cron（教學版，單機檔案模擬叢集核心邏輯）
 * ---------------------------------------------------------------
 * 真實系統：多台 scheduler/worker 常駐進程、共用協調儲存（DB / etcd / ZooKeeper）、
 * 用分散式鎖 + leader 選舉避免重複掃描，worker 心跳偵測當掉。
 *
 * PHP 無跨請求常駐計時器，因此這裡用「手動 tick 推進邏輯時鐘」做確定性模擬：
 * 由 POST /api/tick 把邏輯時鐘往前推，到點的 job 進入待執行；多個 worker 用
 * 「claim（含 fencing token + 租約到期）」搶同一個 job-fire，只允許一個成功認領執行。
 *
 * 以下邏輯都是真的、可實際執行：
 *   - 排程定義：每 N tick 觸發一次（簡化 cron / 固定間隔），記 nextRun
 *   - 到點觸發：tick 推進邏輯時鐘，nextRun <= now 的 job 變成一個「fire」
 *   - 防重複執行（核心）：每個 fire 用 claim 鎖（fencing token + 租約），
 *     多 worker 競爭只有一個成功 → 確保每次觸發只跑一次（at-least-once + 去重）
 *   - misfire 補跑：worker 當掉/離線錯過觸發 → 依策略（fire-once / skip / fire-all）處理
 *   - 分片：job 依雜湊分到「線上 worker」，分散掃描與執行負載
 *
 * 所有狀態存單一 JSON 檔，讀寫用 flock 保護，支援多請求併發。
 */
final class Scheduler
{
    private string $dataDir;
    private string $stateFile;

    /** claim 租約長度（以邏輯 tick 計）：worker 認領後須在租約內完成，否則視為失聯可被重新認領 */
    private const LEASE_TICKS = 5;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dataDir = rtrim($dataDir, '/\\');
        $this->stateFile = $this->dataDir . '/scheduler.json';
        if (!file_exists($this->stateFile)) {
            $this->writeState($this->freshState());
        }
    }

    // ============ 狀態結構 ============

    /**
     * @return array{
     *   now:int,
     *   fenceSeq:int,
     *   jobs:array<string,array>,
     *   workers:array<string,array>,
     *   fires:list<array>,
     *   runs:list<array>
     * }
     */
    private function freshState(): array
    {
        return [
            'now' => 0,        // 邏輯時鐘（tick 數）
            'fenceSeq' => 0,   // 全域遞增的 fencing token 來源
            'jobs' => [],      // jobId => { id, interval, nextRun, misfirePolicy, lastFiredAt }
            'workers' => [],   // workerId => { id, online, claimedFires }
            'fires' => [],     // 每次到點觸發產生一筆 fire（含 claim 狀態）
            'runs' => [],      // 實際執行歷史（去重後每個 fire 至多一筆 done）
        ];
    }

    // ============ Job 排程定義 ============

    /**
     * 新增/更新一個排程 job。
     * @param int    $interval      每 N tick 觸發一次（>=1）
     * @param string $misfirePolicy fire-once | skip | fire-all
     */
    public function addJob(string $jobId, int $interval, string $misfirePolicy = 'fire-once'): array
    {
        $jobId = $this->safeName($jobId);
        if ($interval < 1) {
            $interval = 1;
        }
        if (!in_array($misfirePolicy, ['fire-once', 'skip', 'fire-all'], true)) {
            $misfirePolicy = 'fire-once';
        }
        $state = $this->readState();
        $now = $state['now'];
        $state['jobs'][$jobId] = [
            'id' => $jobId,
            'interval' => $interval,
            'nextRun' => $now + $interval, // 下次觸發時間（邏輯 tick）
            'misfirePolicy' => $misfirePolicy,
            'lastFiredAt' => null,
            'shard' => crc32($jobId), // 分片用雜湊（指派 worker 時對線上 worker 取模）
        ];
        $this->writeState($state);
        return $state['jobs'][$jobId];
    }

    // ============ Worker 上線 / 下線 ============

    public function setWorker(string $workerId, bool $online): array
    {
        $workerId = $this->safeName($workerId);
        $state = $this->readState();
        $existing = $state['workers'][$workerId] ?? ['id' => $workerId, 'claimedFires' => []];
        $existing['id'] = $workerId;
        $existing['online'] = $online;
        if (!isset($existing['claimedFires'])) {
            $existing['claimedFires'] = [];
        }
        $state['workers'][$workerId] = $existing;
        $this->writeState($state);
        return $state['workers'][$workerId];
    }

    /** @return list<string> 線上 worker id（排序後，供穩定分片） */
    private function onlineWorkers(array $state): array
    {
        $ids = [];
        foreach ($state['workers'] as $id => $w) {
            if (!empty($w['online'])) {
                $ids[] = $id;
            }
        }
        sort($ids);
        return $ids;
    }

    /** 依雜湊把 job 分片指派給某個線上 worker（同 job 穩定落同一 worker） */
    private function assignedWorker(array $job, array $onlineWorkers): ?string
    {
        $n = count($onlineWorkers);
        if ($n === 0) {
            return null;
        }
        $idx = ((int) $job['shard']) % $n;
        return $onlineWorkers[$idx];
    }

    // ============ tick：推進邏輯時鐘 → 產生到點 fire ============

    /**
     * 把邏輯時鐘往前推 $steps 步；每一步檢查哪些 job 到點，產生 fire。
     * 同時處理 misfire：若某 job 的 nextRun 早已落後於 now（例如指派 worker 當掉沒掃描），
     * 依該 job 的 misfirePolicy 補跑。
     * @return array{fired:list<array>, now:int}
     */
    public function tick(int $steps = 1): array
    {
        if ($steps < 1) {
            $steps = 1;
        }
        $state = $this->readState();
        $newFires = [];

        for ($s = 0; $s < $steps; $s++) {
            $state['now']++;
            $now = $state['now'];

            $onlineWorkers = $this->onlineWorkers($state);

            foreach ($state['jobs'] as $jobId => &$job) {
                if ($job['nextRun'] > $now) {
                    continue; // 還沒到點
                }
                $assigned = $this->assignedWorker($job, $onlineWorkers);

                // 負責掃描/觸發此 job 的 worker 不在線（當掉）→ 這一 tick 沒人掃，
                // nextRun 不推進，讓它持續落後 → 之後恢復時才依 misfire 策略補跑。
                if ($assigned === null) {
                    continue;
                }

                // 到點：可能因之前沒人掃而落後多個週期（misfire）
                $missedCount = (int) floor(($now - $job['nextRun']) / $job['interval']) + 1;

                $firesToCreate = $this->resolveMisfire($job['misfirePolicy'], $missedCount);

                for ($i = 0; $i < $firesToCreate; $i++) {
                    $state['fenceSeq']++;
                    $fire = [
                        'fireId' => 'f' . $state['fenceSeq'],
                        'jobId' => $jobId,
                        'scheduledAt' => $now,         // 邏輯上應觸發的時刻
                        'assignedWorker' => $assigned, // 分片指派（建議由誰執行；無線上 worker 則 null）
                        'claimedBy' => null,           // 實際認領者（claim 成功者）
                        'fenceToken' => null,          // 認領時發的 fencing token
                        'leaseUntil' => null,          // 租約到期（邏輯 tick）
                        'status' => 'pending',         // pending | claimed | done | missed-skip
                        'createdAt' => $now,
                    ];
                    if ($firesToCreate === 1 && $missedCount > 1 && $job['misfirePolicy'] === 'fire-once') {
                        $fire['note'] = "錯過 {$missedCount} 次，補跑一次（fire-once）";
                    }
                    $state['fires'][] = $fire;
                    $newFires[] = $fire;
                }

                // 推進 nextRun 到「下一個未來的觸發點」
                $job['nextRun'] += $missedCount * $job['interval'];
                $job['lastFiredAt'] = $now;
            }
            unset($job);
        }

        $this->writeState($state);
        return ['fired' => $newFires, 'now' => $state['now']];
    }

    /**
     * misfire 策略 → 此次到點要產生幾個 fire。
     *  - fire-once：不論錯過幾次，只補跑一次
     *  - skip     ：直接跳過（產生 0 個 fire，等下一個正常週期）
     *  - fire-all ：每個錯過的週期都補跑（產生 missedCount 個 fire）
     */
    private function resolveMisfire(string $policy, int $missedCount): int
    {
        return match ($policy) {
            'skip' => $missedCount > 1 ? 0 : 1, // 落後才跳過；剛好準時(=1)仍正常跑
            'fire-all' => $missedCount,
            default => 1,                        // fire-once
        };
    }

    // ============ claim：防重複執行（核心） ============

    /**
     * worker 嘗試認領一個 pending（或租約已過期）的 fire。
     * 用 fencing token + 租約到期實現「只讓一個 worker 執行」：
     *   - 同一個 fire 只有第一個 claim 成功的 worker 拿到 token、進入 claimed
     *   - 其他 worker claim 同一 fire 會失敗（already-claimed）
     *   - 若認領者租約到期仍沒 complete（視為當掉），其他 worker 可重新認領（換更大 token）
     *
     * 分片：預設只認領「指派給自己」的 fire；passShard=true 可跨片接管（救援當掉節點）。
     *
     * @return array{ok:bool, reason?:string, fire?:array}
     */
    public function claim(string $workerId, ?string $fireId = null, bool $respectShard = true): array
    {
        $workerId = $this->safeName($workerId);
        $state = $this->readState();

        if (empty($state['workers'][$workerId]['online'])) {
            return ['ok' => false, 'reason' => 'worker 不在線（請先上線）'];
        }
        $now = $state['now'];

        $targetIndex = null;
        foreach ($state['fires'] as $i => $fire) {
            if ($fireId !== null && $fire['fireId'] !== $fireId) {
                continue;
            }
            if ($fire['status'] === 'done' || $fire['status'] === 'missed-skip') {
                continue;
            }
            // 可認領條件：尚未被認領，或租約已過期（原認領者疑似當掉）
            $claimable = ($fire['status'] === 'pending')
                || ($fire['status'] === 'claimed' && $fire['leaseUntil'] !== null && $fire['leaseUntil'] < $now);
            if (!$claimable) {
                continue;
            }
            // 分片：預設只接自己被指派的；若該 fire 沒指派(null) 或開放跨片則可接
            if ($respectShard
                && $fire['assignedWorker'] !== null
                && $fire['assignedWorker'] !== $workerId
                && !($fire['status'] === 'claimed' && $fire['leaseUntil'] < $now) // 接管當掉節點可跨片
            ) {
                continue;
            }
            $targetIndex = $i;
            break;
        }

        if ($targetIndex === null) {
            if ($fireId !== null) {
                // 指名認領但拿不到 → 多半是被別人搶先了
                foreach ($state['fires'] as $fire) {
                    if ($fire['fireId'] === $fireId) {
                        if ($fire['status'] === 'claimed') {
                            return ['ok' => false, 'reason' => "已被 {$fire['claimedBy']} 認領（去重生效）", 'fire' => $fire];
                        }
                        if ($fire['status'] === 'done') {
                            return ['ok' => false, 'reason' => '此次觸發已執行完成（去重生效）', 'fire' => $fire];
                        }
                    }
                }
            }
            return ['ok' => false, 'reason' => '沒有可認領的觸發'];
        }

        // 認領成功：發 fencing token、設租約
        $state['fenceSeq']++;
        $token = $state['fenceSeq'];
        $wasTakeover = $state['fires'][$targetIndex]['status'] === 'claimed';
        $state['fires'][$targetIndex]['claimedBy'] = $workerId;
        $state['fires'][$targetIndex]['fenceToken'] = $token;
        $state['fires'][$targetIndex]['leaseUntil'] = $now + self::LEASE_TICKS;
        $state['fires'][$targetIndex]['status'] = 'claimed';
        if ($wasTakeover) {
            $state['fires'][$targetIndex]['note'] = '租約過期後被接管（疑似原節點當掉）';
        }

        $fire = $state['fires'][$targetIndex];
        $this->writeState($state);
        return ['ok' => true, 'fire' => $fire];
    }

    /**
     * worker 完成執行：用 fencing token 驗證後標記 done。
     * fencing：只接受「持有最新 token」的 complete，舊 token（被接管的殭屍 worker）會被拒絕，
     * 避免它在租約過期後才醒來、重複寫入結果。
     * @return array{ok:bool, reason?:string, fire?:array}
     */
    public function complete(string $workerId, string $fireId, int $fenceToken): array
    {
        $workerId = $this->safeName($workerId);
        $state = $this->readState();
        foreach ($state['fires'] as $i => $fire) {
            if ($fire['fireId'] !== $fireId) {
                continue;
            }
            if ($fire['status'] === 'done') {
                return ['ok' => false, 'reason' => '已完成（去重：每次觸發只算一次）', 'fire' => $fire];
            }
            if ($fire['claimedBy'] !== $workerId) {
                return ['ok' => false, 'reason' => "非認領者（目前認領者：{$fire['claimedBy']}）", 'fire' => $fire];
            }
            if ((int) $fire['fenceToken'] !== $fenceToken) {
                return ['ok' => false, 'reason' => "fencing token 過期（殭屍寫入被拒）", 'fire' => $fire];
            }
            $state['fires'][$i]['status'] = 'done';
            $state['runs'][] = [
                'fireId' => $fireId,
                'jobId' => $fire['jobId'],
                'worker' => $workerId,
                'scheduledAt' => $fire['scheduledAt'],
                'doneAt' => $state['now'],
                'fenceToken' => $fenceToken,
            ];
            $fire = $state['fires'][$i];
            $this->writeState($state);
            return ['ok' => true, 'fire' => $fire];
        }
        return ['ok' => false, 'reason' => '查無此觸發'];
    }

    // ============ 查詢 / 重置 ============

    public function state(): array
    {
        $state = $this->readState();
        $online = $this->onlineWorkers($state);
        // 附上每個 job 目前被分片指派到哪個 worker（即時計算）
        foreach ($state['jobs'] as $id => &$job) {
            $job['assignedTo'] = $this->assignedWorker($job, $online);
        }
        unset($job);
        $state['onlineWorkers'] = $online;
        return $state;
    }

    public function reset(): void
    {
        $this->writeState($this->freshState());
    }

    // ============ 持久化（flock 保護） ============

    private function readState(): array
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            return $this->freshState();
        }
        flock($fp, LOCK_SH);
        $json = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $arr = json_decode((string) $json, true);
        return is_array($arr) ? array_merge($this->freshState(), $arr) : $this->freshState();
    }

    private function writeState(array $state): void
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            throw new RuntimeException('無法開啟狀態檔: ' . $this->stateFile);
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /** 名稱只允許安全字元，避免奇怪輸入 */
    private function safeName(string $name): string
    {
        $name = preg_replace('/[^0-9A-Za-z_\-]/', '', $name) ?? '';
        return $name === '' ? 'default' : $name;
    }
}
