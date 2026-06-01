<?php
declare(strict_types=1);

/**
 * 協調服務 CoordService（教學版，單機檔案模擬 etcd / ZooKeeper 的協調原語）
 * ---------------------------------------------------------------------------
 * 真實系統：etcd / ZooKeeper 是「以 Raft / ZAB 共識協定複製狀態」的 CP 叢集，
 *   多節點靠 quorum（多數決）保證「同一時刻同一把鎖只授予一個持有者」。
 *   client 透過它做：分散式鎖、leader election、watch、服務發現、設定中心。
 *
 * 這裡用「一個狀態檔 state.json + flock 互斥」模擬「被複製、線性一致的單一狀態機」，
 * 但以下協調邏輯都是真的、可實際執行：
 *   - 租約（lease）鎖：acquire(key,owner,ttl) → 鎖空閒或已過期才授予，記到期時間
 *   - fencing token：每次「成功授予」就把全域單調遞增計數器 +1，回傳給持有者
 *       （這是分散式鎖最重要的考點：下游資源只接受比上次更大的 token，
 *        舊持有者 GC 暫停醒來後拿著過期的小 token 寫入會被拒）
 *   - renew / heartbeat：在租約內續租，延長到期時間
 *   - watch：監看某 key，值變更時把通知放進該 watcher 的佇列
 *   - leader election：誰搶到某把鎖 key 誰就是 leader；租約過期 → 他人接任
 *   - 服務發現：register(service,instance,ttl)+heartbeat、discover() 回存活實例
 *   - 設定中心：config put / get + watch
 *
 * 所有讀寫狀態都在「一次 flock(LOCK_EX) 臨界區」內完成 → 模擬線性一致。
 * 時間用 microtime(true)（實際時間）；fencing token 用單調遞增整數（邏輯時鐘）。
 */
final class CoordService
{
    private string $stateFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->stateFile = rtrim($dataDir, '/\\') . '/state.json';
        if (!file_exists($this->stateFile)) {
            file_put_contents($this->stateFile, json_encode($this->emptyState(), JSON_UNESCAPED_UNICODE));
        }
    }

    private function emptyState(): array
    {
        return [
            'fencingCounter' => 0,   // 全域單調遞增 fencing token 來源
            'locks' => [],            // key => {owner, expireAt, fencingToken, version}
            'fenced' => [],           // resource => 該資源已見過的最大 token（fencing 防護）
            'configs' => [],          // key => {value, version}
            'watches' => [],          // key => [ {id, queue:[...]} ]
            'services' => [],         // service => instanceId => {addr, expireAt, meta}
        ];
    }

    // ============ 臨界區：在 flock 內讀-改-寫狀態 ============

    /**
     * 在獨佔鎖內執行 $fn(state)，$fn 可修改 $state（傳參考）並回傳結果；
     * 函式結束後把 $state 寫回檔案。模擬「對複製狀態機提交一筆線性化操作」。
     * @template T
     * @param callable(array&):mixed $fn
     * @return mixed
     */
    private function withState(callable $fn): mixed
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            throw new RuntimeException('無法開啟狀態檔');
        }
        flock($fp, LOCK_EX);
        $raw = '';
        while (($chunk = fread($fp, 65536)) !== false && $chunk !== '') {
            $raw .= $chunk;
        }
        $state = json_decode($raw !== '' ? $raw : '{}', true);
        if (!is_array($state) || $state === []) {
            $state = $this->emptyState();
        }
        // 補齊缺漏欄位（向前相容）
        $state += $this->emptyState();

        $this->expireAll($state);            // 每次操作前先清過期項（lazy GC）
        $result = $fn($state);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $result;
    }

    private function now(): float
    {
        return microtime(true);
    }

    /** lazy 過期：清掉過期的鎖與服務實例，並對 watch 發出變更通知 */
    private function expireAll(array &$state): void
    {
        $now = $this->now();
        foreach ($state['locks'] as $key => $lock) {
            if (($lock['expireAt'] ?? 0) <= $now) {
                unset($state['locks'][$key]);
                $this->notify($state, "lock:$key", ['event' => 'expired', 'key' => $key]);
            }
        }
        foreach ($state['services'] as $svc => $instances) {
            foreach ($instances as $id => $inst) {
                if (($inst['expireAt'] ?? 0) <= $now) {
                    unset($state['services'][$svc][$id]);
                    $this->notify($state, "service:$svc", ['event' => 'down', 'instance' => $id]);
                }
            }
            if (empty($state['services'][$svc])) {
                unset($state['services'][$svc]);
            }
        }
    }

    // ============ 分散式鎖（租約 lease + fencing token） ============

    /**
     * acquire：嘗試取得鎖 key。鎖空閒或已過期才授予。
     * 成功 → 配一個「比上次更大的 fencing token」，記 owner + 到期時間。
     * @return array{ok:bool, key:string, owner?:string, fencingToken?:int, expireAt?:float, ttl?:float, heldBy?:string, message?:string}
     */
    public function acquire(string $key, string $owner, float $ttl): array
    {
        return $this->withState(function (array &$state) use ($key, $owner, $ttl): array {
            $now = $this->now();
            $existing = $state['locks'][$key] ?? null;

            // 同一持有者重入 → 視為續租（冪等）
            if ($existing !== null && ($existing['owner'] ?? null) === $owner) {
                $state['locks'][$key]['expireAt'] = $now + $ttl;
                return [
                    'ok' => true,
                    'key' => $key,
                    'owner' => $owner,
                    'fencingToken' => $existing['fencingToken'],
                    'expireAt' => $state['locks'][$key]['expireAt'],
                    'ttl' => $ttl,
                    'message' => '已持有，續租（reentrant）',
                ];
            }

            // 鎖被別人持有且未過期 → 失敗
            if ($existing !== null && ($existing['expireAt'] ?? 0) > $now) {
                return [
                    'ok' => false,
                    'key' => $key,
                    'heldBy' => (string) $existing['owner'],
                    'fencingToken' => $existing['fencingToken'],
                    'message' => '鎖已被他人持有（未過期）',
                ];
            }

            // 空閒或已過期 → 授予，配新的 fencing token（單調遞增）
            $token = ++$state['fencingCounter'];
            $state['locks'][$key] = [
                'owner' => $owner,
                'expireAt' => $now + $ttl,
                'fencingToken' => $token,
            ];
            $this->notify($state, "lock:$key", ['event' => 'acquired', 'key' => $key, 'owner' => $owner, 'fencingToken' => $token]);
            return [
                'ok' => true,
                'key' => $key,
                'owner' => $owner,
                'fencingToken' => $token,
                'expireAt' => $now + $ttl,
                'ttl' => $ttl,
                'message' => '取得鎖',
            ];
        });
    }

    /** release：釋放鎖（僅持有者本人可釋放） */
    public function release(string $key, string $owner): array
    {
        return $this->withState(function (array &$state) use ($key, $owner): array {
            $existing = $state['locks'][$key] ?? null;
            if ($existing === null) {
                return ['ok' => false, 'key' => $key, 'message' => '鎖不存在或已過期'];
            }
            if (($existing['owner'] ?? null) !== $owner) {
                return ['ok' => false, 'key' => $key, 'message' => '非持有者，不可釋放', 'heldBy' => $existing['owner']];
            }
            unset($state['locks'][$key]);
            $this->notify($state, "lock:$key", ['event' => 'released', 'key' => $key, 'owner' => $owner]);
            return ['ok' => true, 'key' => $key, 'message' => '已釋放'];
        });
    }

    /** renew / heartbeat：在租約內續租，延長到期時間（必須仍是持有者） */
    public function renew(string $key, string $owner, float $ttl): array
    {
        return $this->withState(function (array &$state) use ($key, $owner, $ttl): array {
            $now = $this->now();
            $existing = $state['locks'][$key] ?? null;
            if ($existing === null) {
                return ['ok' => false, 'key' => $key, 'message' => '租約已過期，鎖已被回收（需重新 acquire）'];
            }
            if (($existing['owner'] ?? null) !== $owner) {
                return ['ok' => false, 'key' => $key, 'message' => '非持有者，不可續租', 'heldBy' => $existing['owner']];
            }
            $state['locks'][$key]['expireAt'] = $now + $ttl;
            return [
                'ok' => true,
                'key' => $key,
                'owner' => $owner,
                'fencingToken' => $existing['fencingToken'],
                'expireAt' => $now + $ttl,
                'ttl' => $ttl,
                'message' => '續租成功',
            ];
        });
    }

    /** 查鎖目前狀態（持有者、token、剩餘秒數） */
    public function lockStatus(string $key): array
    {
        return $this->withState(function (array &$state) use ($key): array {
            $lock = $state['locks'][$key] ?? null;
            if ($lock === null) {
                return ['key' => $key, 'held' => false];
            }
            return [
                'key' => $key,
                'held' => true,
                'owner' => $lock['owner'],
                'fencingToken' => $lock['fencingToken'],
                'expireAt' => $lock['expireAt'],
                'remaining' => round($lock['expireAt'] - $this->now(), 3),
            ];
        });
    }

    // ============ fencing token 防護（最重要考點） ============

    /**
     * 模擬「下游受保護資源」的寫入。資源只接受「比上次見過更大的 fencing token」。
     * 場景：持鎖者 A GC 暫停 → 租約過期 → B 取得鎖（拿到更大 token）並寫入 →
     *       A 醒來不知自己已失去鎖，拿著舊（較小）token 來寫 → 被資源拒絕。
     * 這就是分散式鎖「即使鎖會誤判過期，下游仍安全」的關鍵。
     *
     * @return array{ok:bool, resource:string, token:int, lastSeen:int, message:string}
     */
    public function fenceWrite(string $resource, int $token, mixed $payload): array
    {
        return $this->withState(function (array &$state) use ($resource, $token, $payload): array {
            $lastSeen = $state['fenced'][$resource] ?? 0;
            if ($token <= $lastSeen) {
                return [
                    'ok' => false,
                    'resource' => $resource,
                    'token' => $token,
                    'lastSeen' => $lastSeen,
                    'message' => "拒絕：token {$token} <= 已見過的 {$lastSeen}（過期持有者的 stale 寫入被 fence 掉）",
                ];
            }
            $state['fenced'][$resource] = $token;
            $state['configs']["__resource__$resource"] = ['value' => $payload, 'version' => $token];
            return [
                'ok' => true,
                'resource' => $resource,
                'token' => $token,
                'lastSeen' => $lastSeen,
                'message' => "接受：token {$token} > {$lastSeen}，寫入生效",
            ];
        });
    }

    // ============ leader election（用鎖 key 實作） ============

    /**
     * campaign：競選某 election key 的 leader。
     * 本質就是 acquire 那把鎖：搶到鎖（拿到 fencing token / leader epoch）者即 leader；
     * 持有者掉線（不續租 → 租約過期）後，他人 acquire 即接任，epoch 遞增。
     */
    public function campaign(string $election, string $candidate, float $ttl): array
    {
        $r = $this->acquire("election/$election", $candidate, $ttl);
        if ($r['ok']) {
            return [
                'isLeader' => true,
                'election' => $election,
                'leader' => $candidate,
                'epoch' => $r['fencingToken'], // leader epoch = fencing token
                'expireAt' => $r['expireAt'],
                'message' => '當選 leader',
            ];
        }
        return [
            'isLeader' => false,
            'election' => $election,
            'leader' => $r['heldBy'] ?? null,
            'epoch' => $r['fencingToken'] ?? null,
            'message' => 'leader 已存在（落選，將監看其租約）',
        ];
    }

    public function leader(string $election): array
    {
        return $this->lockStatus("election/$election");
    }

    // ============ watch（變更通知佇列） ============

    /** 註冊一個 watcher，回傳 watcherId；之後 poll 取出該 key 的變更事件 */
    public function watch(string $key): array
    {
        return $this->withState(function (array &$state) use ($key): array {
            $id = bin2hex(random_bytes(6));
            $state['watches'][$key] ??= [];
            $state['watches'][$key][$id] = ['queue' => []];
            return ['watcherId' => $id, 'key' => $key, 'message' => "已監看 $key"];
        });
    }

    /** poll：取出並清空某 watcher 累積的變更事件（阻塞式 watch 的單機簡化版） */
    public function pollWatch(string $key, string $watcherId): array
    {
        return $this->withState(function (array &$state) use ($key, $watcherId): array {
            $w = $state['watches'][$key][$watcherId] ?? null;
            if ($w === null) {
                return ['ok' => false, 'message' => 'watcher 不存在（可能已過期）', 'events' => []];
            }
            $events = $state['watches'][$key][$watcherId]['queue'];
            $state['watches'][$key][$watcherId]['queue'] = [];
            return ['ok' => true, 'key' => $key, 'watcherId' => $watcherId, 'events' => $events];
        });
    }

    /** 對某 key 的所有 watcher 推一筆事件（內部用，呼叫端已在臨界區內） */
    private function notify(array &$state, string $key, array $event): void
    {
        if (!isset($state['watches'][$key])) {
            return;
        }
        $event['ts'] = $this->now();
        foreach ($state['watches'][$key] as $id => $w) {
            $state['watches'][$key][$id]['queue'][] = $event;
        }
    }

    // ============ 設定中心（config put / get + watch） ============

    public function putConfig(string $key, mixed $value): array
    {
        return $this->withState(function (array &$state) use ($key, $value): array {
            $old = $state['configs'][$key] ?? null;
            $version = ($old['version'] ?? 0) + 1;
            $state['configs'][$key] = ['value' => $value, 'version' => $version];
            $this->notify($state, "config:$key", ['event' => 'put', 'key' => $key, 'value' => $value, 'version' => $version]);
            return ['ok' => true, 'key' => $key, 'value' => $value, 'version' => $version, 'message' => '設定已更新並通知 watcher'];
        });
    }

    public function getConfig(string $key): array
    {
        return $this->withState(function (array &$state) use ($key): array {
            $c = $state['configs'][$key] ?? null;
            if ($c === null) {
                return ['ok' => false, 'key' => $key, 'message' => '設定不存在'];
            }
            return ['ok' => true, 'key' => $key, 'value' => $c['value'], 'version' => $c['version']];
        });
    }

    // ============ 服務發現（register + heartbeat + discover） ============

    /**
     * register：服務實例註冊（帶 ttl，相當於 ephemeral node）。
     * 之後須週期性 register/heartbeat 續租；漏心跳 → 租約過期 → 自動從清單移除。
     */
    public function register(string $service, string $instanceId, string $addr, float $ttl, array $meta = []): array
    {
        return $this->withState(function (array &$state) use ($service, $instanceId, $addr, $ttl, $meta): array {
            $now = $this->now();
            $isNew = !isset($state['services'][$service][$instanceId]);
            $state['services'][$service][$instanceId] = [
                'addr' => $addr,
                'expireAt' => $now + $ttl,
                'meta' => $meta,
            ];
            if ($isNew) {
                $this->notify($state, "service:$service", ['event' => 'up', 'instance' => $instanceId, 'addr' => $addr]);
            }
            return [
                'ok' => true,
                'service' => $service,
                'instance' => $instanceId,
                'addr' => $addr,
                'expireAt' => $now + $ttl,
                'message' => $isNew ? '註冊成功' : '心跳續租',
            ];
        });
    }

    /** discover：回傳某服務目前「存活」的實例（已自動過濾過期實例） */
    public function discover(string $service): array
    {
        return $this->withState(function (array &$state) use ($service): array {
            $now = $this->now();
            $alive = [];
            foreach ($state['services'][$service] ?? [] as $id => $inst) {
                $alive[] = [
                    'instance' => $id,
                    'addr' => $inst['addr'],
                    'remaining' => round($inst['expireAt'] - $now, 3),
                    'meta' => $inst['meta'] ?? [],
                ];
            }
            return ['service' => $service, 'instances' => $alive, 'count' => count($alive)];
        });
    }

    /** 整體狀態快照（測試頁用） */
    public function snapshot(): array
    {
        return $this->withState(function (array &$state): array {
            $now = $this->now();
            $locks = [];
            foreach ($state['locks'] as $k => $l) {
                $locks[$k] = ['owner' => $l['owner'], 'token' => $l['fencingToken'], 'remaining' => round($l['expireAt'] - $now, 3)];
            }
            $svcs = [];
            foreach ($state['services'] as $svc => $inst) {
                $svcs[$svc] = count($inst);
            }
            return [
                'fencingCounter' => $state['fencingCounter'],
                'locks' => $locks,
                'fenced' => $state['fenced'],
                'services' => $svcs,
                'configKeys' => array_keys($state['configs']),
            ];
        });
    }
}
