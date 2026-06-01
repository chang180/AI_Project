<?php
declare(strict_types=1);

/**
 * 秒殺 / 搶票核心邏輯（防超賣 + 冪等 + 限購 + 入場令牌）
 * --------------------------------------------------------------
 * 真實系統：
 *   - 庫存熱 key 放 Redis，用 DECR / Lua 做原子條件扣減；或 DB 用
 *     UPDATE stock SET remaining=remaining-1 WHERE id=? AND remaining>0
 *     看 affected_rows 判斷是否搶到（行鎖天然防超賣）。
 *   - 落單走 MQ 非同步、冪等鍵去重、令牌/排隊削峰。
 *
 * 本 Demo（單機教學版，誠實聲明）：
 *   - 用 flock 檔案鎖把「讀-改-寫」包成臨界區，扮演 Redis 原子操作 /
 *     DB 行鎖的角色。所有共享狀態（庫存、訂單、冪等、令牌、每人已購）
 *     都存在同一個 JSON 狀態檔，整段交易在持有 LOCK_EX 期間完成，
 *     因此「判斷 remaining>0 + 扣減」是原子的 → 絕不超賣。
 *   - 防超賣 / 冪等 / 限購 / 令牌的邏輯皆為真實可執行。
 */
final class FlashSale
{
    private string $stateFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->stateFile = $dataDir . '/flashsale.json';
        if (!file_exists($this->stateFile)) {
            file_put_contents($this->stateFile, json_encode($this->emptyState()));
        }
    }

    private function emptyState(): array
    {
        return [
            'sale' => [
                'name'           => '預設場次',
                'stock_total'    => 0,   // 初始庫存
                'remaining'      => 0,   // 目前剩餘（原子扣減對象）
                'per_user_limit' => 1,   // 每人限購
                'token_capacity' => 0,   // 入場令牌上限
                'tokens_issued'  => 0,   // 已發令牌數
            ],
            'orders'    => [],   // order_id => {sale, user, qty, ...}
            'idem'      => [],   // idem_key => {order_id, status, qty}
            'purchased' => [],   // user => 已購數量
            'tokens'    => [],   // token => user（已發出、未使用的有效令牌）
            'seq'       => 0,    // 訂單流水號
        ];
    }

    /* ============================================================
     * 原子操作核心：在 flock(LOCK_EX) 臨界區內執行 callback($state)
     * callback 回傳 [新狀態, 回傳值]；若回傳 null 則不寫回。
     * 這就是「讀-改-寫」原子化的唯一入口。
     * ============================================================ */
    private function atomic(callable $fn): mixed
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            throw new RuntimeException('cannot open state file');
        }
        try {
            flock($fp, LOCK_EX);                       // 進入臨界區
            $raw = stream_get_contents($fp);
            $state = json_decode($raw === '' ? '' : (string) $raw, true);
            if (!is_array($state)) {
                $state = $this->emptyState();
            }

            [$newState, $ret] = $fn($state);

            if ($newState !== null) {                  // 需要寫回時才覆寫
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode(
                    $newState,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                ));
                fflush($fp);
            }
            return $ret;
        } finally {
            flock($fp, LOCK_UN);                        // 離開臨界區
            fclose($fp);
        }
    }

    /** 唯讀快照（高頻讀，不進臨界區的寫路徑） */
    private function snapshot(): array
    {
        $raw = (string) file_get_contents($this->stateFile);
        $s = json_decode($raw, true);
        return is_array($s) ? $s : $this->emptyState();
    }

    /* ============================================================
     * 設定 / 重置場次（設定初始庫存、限購、令牌上限）
     * ============================================================ */
    public function configure(
        string $name,
        int $stock,
        int $perUserLimit,
        int $tokenCapacity
    ): array {
        return $this->atomic(function (array $s) use ($name, $stock, $perUserLimit, $tokenCapacity) {
            $fresh = $this->emptyState();
            $fresh['sale'] = [
                'name'           => $name,
                'stock_total'    => max(0, $stock),
                'remaining'      => max(0, $stock),
                'per_user_limit' => max(1, $perUserLimit),
                'token_capacity' => max(0, $tokenCapacity),
                'tokens_issued'  => 0,
            ];
            return [$fresh, $fresh['sale']];
        });
    }

    /* ============================================================
     * 領入場令牌（削峰第一關，限量）
     * 回 ['token'=>..] 或 ['error'=>'queued']（令牌發完）
     * ============================================================ */
    public function issueToken(string $user): array
    {
        return $this->atomic(function (array $s) use ($user) {
            $cap = (int) $s['sale']['token_capacity'];
            if ($cap > 0 && (int) $s['sale']['tokens_issued'] >= $cap) {
                // 令牌已發完 → 擋在門外（排隊 / 稍後再試）
                return [null, ['error' => 'queued']];
            }
            $token = 'tk_' . bin2hex(random_bytes(8));
            $s['sale']['tokens_issued'] = (int) $s['sale']['tokens_issued'] + 1;
            $s['tokens'][$token] = $user;
            return [$s, ['token' => $token]];
        });
    }

    /* ============================================================
     * 下單（核心關鍵路徑）：令牌驗證 → 冪等去重 → 限購 →
     *                       原子條件扣減（remaining>0 才扣）
     * 整段都在臨界區內 → 並發安全、絕不超賣。
     * ============================================================ */
    public function buy(string $user, string $idemKey, string $token, int $qty = 1): array
    {
        $qty = max(1, $qty);
        return $this->atomic(function (array $s) use ($user, $idemKey, $token, $qty) {
            // (0) 冪等：同 idem_key 已處理過 → 直接回原結果，不重扣
            if ($idemKey !== '' && isset($s['idem'][$idemKey])) {
                $prev = $s['idem'][$idemKey];
                return [null, $prev + ['idempotent' => true]];
            }

            // (1) 令牌驗證：必須持有有效入場令牌才准下單
            $tokenRequired = ((int) $s['sale']['token_capacity']) > 0;
            if ($tokenRequired) {
                if ($token === '' || !isset($s['tokens'][$token]) || $s['tokens'][$token] !== $user) {
                    return [null, ['status' => 'rejected', 'error' => 'invalid_token']];
                }
            }

            // (2) 每人限購檢查（在臨界區內，並發無法繞過）
            $already = (int) ($s['purchased'][$user] ?? 0);
            $limit = (int) $s['sale']['per_user_limit'];
            if ($already + $qty > $limit) {
                return [null, ['status' => 'rejected', 'error' => 'limit_exceeded',
                               'already' => $already, 'limit' => $limit]];
            }

            // (3) 原子條件扣減（CAS）：只有 remaining>=qty 才扣
            $remaining = (int) $s['sale']['remaining'];
            if ($remaining < $qty) {
                return [null, ['status' => 'rejected', 'error' => 'sold_out',
                               'remaining' => $remaining]];
            }
            $s['sale']['remaining'] = $remaining - $qty;     // 扣庫存
            $s['purchased'][$user]  = $already + $qty;        // 記每人已購

            // (4) 落單（真實系統走 MQ 非同步；這裡直接寫）
            $s['seq'] = (int) $s['seq'] + 1;
            $orderId = 'ord_' . str_pad((string) $s['seq'], 6, '0', STR_PAD_LEFT);
            $s['orders'][$orderId] = [
                'order_id'   => $orderId,
                'user'       => $user,
                'qty'        => $qty,
                'created_at' => gmdate('c'),
            ];

            // (5) 令牌消耗（一次性）
            if ($tokenRequired) {
                unset($s['tokens'][$token]);
            }

            $result = ['status' => 'ok', 'order_id' => $orderId, 'qty' => $qty,
                       'remaining' => $s['sale']['remaining']];

            // (6) 記冪等結果，之後同鍵重送回原訂單
            if ($idemKey !== '') {
                $s['idem'][$idemKey] = ['status' => 'ok', 'order_id' => $orderId, 'qty' => $qty];
            }
            return [$s, $result];
        });
    }

    /* ============================================================
     * 查庫存（高頻讀，走唯讀快照）
     * ============================================================ */
    public function stock(): array
    {
        $s = $this->snapshot();
        return [
            'name'        => $s['sale']['name'],
            'stock_total' => (int) $s['sale']['stock_total'],
            'remaining'   => (int) $s['sale']['remaining'],
            'sold'        => (int) $s['sale']['stock_total'] - (int) $s['sale']['remaining'],
            'sold_out'    => ((int) $s['sale']['remaining']) <= 0,
        ];
    }

    public function stats(): array
    {
        $s = $this->snapshot();
        return [
            'sale'        => $s['sale'],
            'order_count' => count($s['orders']),
            'idem_count'  => count($s['idem']),
            'buyers'      => count($s['purchased']),
        ];
    }

    /* ============================================================
     * 一鍵壓測模擬：M 個 user 各搶 1 張，共 K 張庫存。
     * 重點：模擬高並發競爭後，驗證「售出 == 初始庫存」（無超賣），
     *       並另外驗證同一 user 帶相同 idem_key 重送 → 不重複扣。
     * ============================================================ */
    public function simulate(int $users, int $stock, int $perUserLimit = 1, bool $useToken = true): array
    {
        $tokenCap = $useToken ? (int) ceil($stock * 1.2) : 0; // 令牌略多於庫存，留緩衝
        $this->configure('壓測場次', $stock, $perUserLimit, $tokenCap);

        $sold = 0;
        $soldOut = 0;
        $limitHit = 0;
        $queued = 0;
        $tokenRejected = 0;

        // 打散順序模擬亂序競爭
        $order = range(0, max(0, $users - 1));
        shuffle($order);

        foreach ($order as $i) {
            $user = 'u' . $i;

            // 先領令牌（削峰）
            $token = '';
            if ($useToken) {
                $t = $this->issueToken($user);
                if (isset($t['error'])) {   // 令牌發完 → 被擋（排隊）
                    $queued++;
                    continue;
                }
                $token = $t['token'];
            }

            // 下單（每人獨立 idem_key）
            $res = $this->buy($user, "idem_$i", $token, 1);
            if (($res['status'] ?? '') === 'ok') {
                $sold++;
            } elseif (($res['error'] ?? '') === 'sold_out') {
                $soldOut++;
            } elseif (($res['error'] ?? '') === 'limit_exceeded') {
                $limitHit++;
            } elseif (($res['error'] ?? '') === 'invalid_token') {
                $tokenRejected++;
            }
        }

        // 額外驗證冪等：取一位已成功的 user，用相同 idem_key 重送 3 次
        $idemProof = ['tested' => false];
        $firstBuyerIdx = $order[0] ?? null;
        // 找一個確實買到的（簡單從前面掃）
        for ($k = 0; $k < $users; $k++) {
            $probe = $this->buy('u' . $k, "idem_$k", '', 1); // 重送同 idem_key（令牌已消耗，但冪等優先命中）
            if (!empty($probe['idempotent']) && ($probe['status'] ?? '') === 'ok') {
                // 連送 2 次，確認回同一 order_id 且庫存不再變動
                $before = $this->stock()['remaining'];
                $again  = $this->buy('u' . $k, "idem_$k", '', 1);
                $after  = $this->stock()['remaining'];
                $idemProof = [
                    'tested'        => true,
                    'user'          => 'u' . $k,
                    'idem_key'      => "idem_$k",
                    'order_id'      => $probe['order_id'] ?? null,
                    'same_order'    => ($probe['order_id'] ?? null) === ($again['order_id'] ?? null),
                    'stock_unchanged' => $before === $after,
                ];
                break;
            }
        }

        $final = $this->stock();
        return [
            'config' => [
                'users'          => $users,
                'stock'          => $stock,
                'per_user_limit' => $perUserLimit,
                'use_token'      => $useToken,
                'token_capacity' => $tokenCap,
            ],
            'result' => [
                'sold'           => $sold,
                'sold_out_reject'=> $soldOut,
                'limit_reject'   => $limitHit,
                'token_queued'   => $queued,
                'token_rejected' => $tokenRejected,
            ],
            'verify' => [
                'final_remaining' => $final['remaining'],
                'final_sold'      => $final['sold'],
                // 核心斷言：售出 == 初始庫存（庫存足夠時），且不超賣
                'no_oversell'     => $final['sold'] <= $stock && $final['remaining'] >= 0,
                'sold_equals_stock' => $final['sold'] === min($stock, $users),
                'idempotency'     => $idemProof,
            ],
        ];
    }
}
