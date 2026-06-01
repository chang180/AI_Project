<?php
declare(strict_types=1);

/**
 * API Gateway / Load Balancer — 核心引擎
 * ============================================================
 * 把「一個請求 → 挑到哪一台後端」這條決策鏈做出來，也是面試真正考點：
 *
 *   1) 路由（routing）       依路徑前綴把請求導到對應的 upstream 服務池。
 *   2) 負載均衡（LB）        在池內挑一台健康後端，支援三種演算法可切換：
 *                            round-robin / least-connection / consistent-hash。
 *   3) 健康檢查（health）    後端被標記 down → 自動繞過，只導到健康的。
 *   4) 熔斷（circuit breaker）某後端連續失敗 N 次 → 打開熔斷暫時不導流；
 *                            冷卻一段時間後進入「半開」放一個試探請求探活。
 *
 * 狀態（服務池、健康、連線數、熔斷器、輪詢游標）放在一個 JSON 檔，
 * 所有讀-改-寫用 flock(LOCK_EX) 包成單一原子區塊，模擬真實 Gateway
 * 在多節點/多請求併發下對共享狀態（通常在 Redis / etcd / 一致性儲存）
 * 的原子更新。單機示意，但路由 / LB / 健康檢查 / 熔斷邏輯全為真。
 */
final class Gateway
{
    /** 熔斷門檻：連續失敗幾次就打開熔斷 */
    private const FAIL_THRESHOLD = 3;
    /** 熔斷冷卻秒數：打開後多久進入半開試探 */
    private const COOLDOWN_SEC = 10;

    private string $stateFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->stateFile = $dataDir . '/gateway.json';
        if (!file_exists($this->stateFile)) {
            file_put_contents($this->stateFile, json_encode($this->freshState()));
        }
    }

    /** 全新空狀態：演算法預設 round-robin */
    private function freshState(): array
    {
        return [
            'algo'      => 'round_robin',          // round_robin | least_conn | consistent_hash
            'upstreams' => [],                      // prefix => [ backends..., rr_cursor ]
        ];
    }

    /** 後端初始結構 */
    private function freshBackend(string $id): array
    {
        return [
            'id'          => $id,
            'up'          => true,   // 健康檢查：true=健康，false=被 kill
            'conns'       => 0,      // 目前在處理的連線數（least-conn 用）
            'served'      => 0,      // 累計被選中導流次數（看分布用）
            'fails'       => 0,      // 連續失敗計數（熔斷用）
            // 熔斷器狀態：closed=正常導流 / open=熔斷中不導流 / half_open=放一個試探
            'cb'          => 'closed',
            'open_until'  => 0.0,    // open 狀態到此時間（epoch 秒）後可進半開
        ];
    }

    // ---------------------------------------------------------------
    // 對外 API（由 public/index.php 呼叫）
    // ---------------------------------------------------------------

    /** 取得整份狀態（供前端顯示） */
    public function state(): array
    {
        return $this->withLock(function (array $s): array {
            $this->refreshCircuit($s);   // 讀取時也順手把過冷卻的熔斷器轉半開
            return $s;
        }, true);
    }

    /** 切換負載均衡演算法 */
    public function setAlgo(string $algo): array
    {
        return $this->withLock(function (array &$s) use ($algo): array {
            $s['algo'] = in_array($algo, ['round_robin', 'least_conn', 'consistent_hash'], true)
                ? $algo : 'round_robin';
            return ['ok' => true, 'algo' => $s['algo']];
        });
    }

    /**
     * 註冊一個服務池：某路徑前綴 → 一組後端。
     * @param string   $prefix   如 "/users"
     * @param string[] $backends 後端代號，如 ["users-1","users-2","users-3"]
     */
    public function registerUpstream(string $prefix, array $backends): array
    {
        $prefix = $this->normPrefix($prefix);
        return $this->withLock(function (array &$s) use ($prefix, $backends): array {
            $list = [];
            foreach ($backends as $b) {
                $b = trim((string) $b);
                if ($b === '') { continue; }
                $list[] = $this->freshBackend($b);
            }
            if ($list === []) {
                return ['ok' => false, 'error' => 'no backends'];
            }
            $s['upstreams'][$prefix] = [
                'backends'  => $list,
                'rr_cursor' => 0,
            ];
            return ['ok' => true, 'prefix' => $prefix, 'count' => count($list)];
        });
    }

    /**
     * 模擬轉發一次請求：依路徑路由到服務池 → LB 挑一台健康後端 → 回報挑中誰。
     * 可選 $fail=true 模擬這次後端處理失敗（餵給熔斷器）。
     * 可選 $hashKey 供 consistent-hash 用（如 user id / session）；預設用 path。
     *
     * @return array 路由與挑選結果（含每一步的決策說明）
     */
    public function route(string $path, bool $fail = false, string $hashKey = ''): array
    {
        $path = '/' . ltrim(trim($path), '/');
        return $this->withLock(function (array &$s) use ($path, $fail, $hashKey): array {
            $this->refreshCircuit($s);

            // ---- 1) 路由：找最長前綴符合的服務池 ----
            $prefix = $this->matchPrefix($s, $path);
            if ($prefix === null) {
                return ['ok' => false, 'status' => 404, 'reason' => 'no route for ' . $path];
            }
            $pool = &$s['upstreams'][$prefix];

            // ---- 2) 候選池：只留「健康」且「熔斷器非 open」的後端 ----
            $candidates = [];
            foreach ($pool['backends'] as $i => $b) {
                if (!$b['up']) { continue; }                 // 健康檢查繞過
                if ($b['cb'] === 'open') { continue; }        // 熔斷中不導流
                $candidates[] = $i;                           // half_open / closed 皆可被選
            }
            if ($candidates === []) {
                return [
                    'ok' => false, 'status' => 503, 'prefix' => $prefix,
                    'reason' => '池內無可用後端（全部 down 或熔斷中）',
                ];
            }

            // ---- 3) 負載均衡：依演算法挑一台 ----
            $key = $hashKey !== '' ? $hashKey : $path;
            $chosenIdx = $this->pick($s['algo'], $pool, $candidates, $key);
            $b = &$pool['backends'][$chosenIdx];

            $halfProbe = ($b['cb'] === 'half_open');

            // ---- 4) 模擬處理 + 餵熔斷器 ----
            $b['served']++;
            $b['conns']++;   // demo 是同步請求，這裡 +1 馬上 -1；保留欄位示意 least-conn
            $outcome = $fail ? 'fail' : 'ok';

            if ($fail) {
                $b['fails']++;
                if ($b['fails'] >= self::FAIL_THRESHOLD) {
                    // 連續失敗達門檻 → 打開熔斷
                    $b['cb'] = 'open';
                    $b['open_until'] = microtime(true) + self::COOLDOWN_SEC;
                } elseif ($halfProbe) {
                    // 半開試探又失敗 → 立刻重新打開，再等一輪冷卻
                    $b['cb'] = 'open';
                    $b['open_until'] = microtime(true) + self::COOLDOWN_SEC;
                }
            } else {
                // 成功 → 失敗計數歸零；若是半開試探成功 → 關閉熔斷恢復正常
                $b['fails'] = 0;
                if ($halfProbe) {
                    $b['cb'] = 'closed';
                }
            }
            $b['conns'] = max(0, $b['conns'] - 1);

            return [
                'ok'        => !$fail,
                'status'    => $fail ? 502 : 200,
                'prefix'    => $prefix,
                'algo'      => $s['algo'],
                'chosen'    => $b['id'],
                'outcome'   => $outcome,
                'probe'     => $halfProbe,          // 這次是不是半開試探
                'cb_after'  => $b['cb'],            // 這台後端事後的熔斷狀態
                'fails'     => $b['fails'],
                'candidates'=> array_map(fn($i) => $pool['backends'][$i]['id'], $candidates),
            ];
        });
    }

    /** 把某後端標記 down（健康檢查失敗 / 手動下線）或重新拉起 up */
    public function kill(string $prefix, string $backendId, bool $up = false): array
    {
        $prefix = $this->normPrefix($prefix);
        return $this->withLock(function (array &$s) use ($prefix, $backendId, $up): array {
            if (!isset($s['upstreams'][$prefix])) {
                return ['ok' => false, 'error' => 'no such upstream'];
            }
            foreach ($s['upstreams'][$prefix]['backends'] as &$b) {
                if ($b['id'] === $backendId) {
                    $b['up'] = $up;
                    if ($up) {
                        // 重新拉起 → 重置熔斷與失敗計數
                        $b['cb'] = 'closed';
                        $b['fails'] = 0;
                        $b['open_until'] = 0.0;
                    }
                    return ['ok' => true, 'backend' => $backendId, 'up' => $up];
                }
            }
            return ['ok' => false, 'error' => 'no such backend'];
        });
    }

    /** 全部重置 */
    public function reset(): array
    {
        return $this->withLock(function (array &$s): array {
            $s = $this->freshState();
            return ['ok' => true];
        });
    }

    // ---------------------------------------------------------------
    // 內部：路由 / LB 演算法 / 熔斷
    // ---------------------------------------------------------------

    /** 最長前綴比對：把請求路徑導到最精確的服務池 */
    private function matchPrefix(array $s, string $path): ?string
    {
        $best = null;
        foreach ($s['upstreams'] as $prefix => $_) {
            if ($prefix === '/' || str_starts_with($path, $prefix)) {
                if ($best === null || strlen($prefix) > strlen($best)) {
                    $best = $prefix;
                }
            }
        }
        return $best;
    }

    /**
     * 負載均衡：在候選後端中挑一台。
     * @param int[] $candidates 候選後端在 backends 陣列中的索引
     * @return int 被選中的索引
     */
    private function pick(string $algo, array &$pool, array $candidates, string $key): int
    {
        if ($algo === 'least_conn') {
            // 最少連線：挑目前 conns 最小的；平手挑 served 較少的
            $best = $candidates[0];
            foreach ($candidates as $i) {
                $b = $pool['backends'][$i];
                $bb = $pool['backends'][$best];
                if ($b['conns'] < $bb['conns']
                    || ($b['conns'] === $bb['conns'] && $b['served'] < $bb['served'])) {
                    $best = $i;
                }
            }
            return $best;
        }

        if ($algo === 'consistent_hash') {
            // 一致性雜湊：同一把 key 永遠落到同一台（只在候選中），
            // 後端增減時只重洗一小部分 key。這裡用「環上順時針找第一個候選」示意。
            return $this->hashPick($pool, $candidates, $key);
        }

        // round-robin（預設）：游標只在候選中前進，天然繞過 down / 熔斷的後端
        $n = count($pool['backends']);
        for ($step = 0; $step < $n; $step++) {
            $pool['rr_cursor'] = ($pool['rr_cursor'] + 1) % $n;
            if (in_array($pool['rr_cursor'], $candidates, true)) {
                return $pool['rr_cursor'];
            }
        }
        return $candidates[0]; // 理論上不會到這
    }

    /**
     * 一致性雜湊：把每台候選後端放上一個 0..2^32 的環（用 crc32 取點），
     * 再把 key 也雜湊到環上，順時針找到第一台 ≥ key 的後端（繞回則取最小）。
     * 真實系統會給每台加數十到上百個「虛擬節點」讓分布更均勻，這裡示意主邏輯。
     */
    private function hashPick(array $pool, array $candidates, string $key): int
    {
        $ring = [];
        foreach ($candidates as $i) {
            $point = crc32($pool['backends'][$i]['id']);
            $ring[] = ['p' => $point, 'idx' => $i];
        }
        usort($ring, fn($a, $b) => $a['p'] <=> $b['p']);

        $kp = crc32($key);
        foreach ($ring as $node) {
            if ($node['p'] >= $kp) {
                return $node['idx'];
            }
        }
        return $ring[0]['idx']; // 繞回環首
    }

    /**
     * 熔斷器時間推進：open 狀態若已過冷卻時間 → 轉 half_open（放一個試探）。
     * 在每次 route / state 前呼叫，讓「冷卻後自動半開」自然發生。
     */
    private function refreshCircuit(array &$s): void
    {
        $now = microtime(true);
        foreach ($s['upstreams'] as &$pool) {
            foreach ($pool['backends'] as &$b) {
                if ($b['cb'] === 'open' && $now >= $b['open_until']) {
                    $b['cb'] = 'half_open';
                }
            }
        }
    }

    // ---------------------------------------------------------------
    // 工具
    // ---------------------------------------------------------------

    private function normPrefix(string $p): string
    {
        $p = '/' . trim(trim($p), '/');
        return $p === '/' ? '/' : rtrim($p, '/');
    }

    /**
     * 原子讀-改-寫：用 flock(LOCK_EX) 把整段包起來，模擬 Redis 原子操作。
     * @param callable $fn      接 array &$state，回傳結果
     * @param bool     $readonly 只讀（state 顯示用）就不寫回
     * @return array
     */
    private function withLock(callable $fn, bool $readonly = false): array
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            return ['ok' => false, 'error' => 'cannot open state'];
        }
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $state = json_decode((string) $raw, true);
        if (!is_array($state) || !isset($state['upstreams'])) {
            $state = $this->freshState();
        }

        $result = $fn($state);

        if (!$readonly) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        // state() 走 readonly 但要回整份 state
        if ($readonly && is_array($result)) {
            return $result;
        }
        return $result;
    }
}
