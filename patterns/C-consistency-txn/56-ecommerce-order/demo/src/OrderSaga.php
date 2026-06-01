<?php
declare(strict_types=1);

require_once __DIR__ . '/OrderStateMachine.php';

/**
 * 電商下單付款編排 Saga（含庫存原子預扣、冪等下單、反向補償）
 * --------------------------------------------------------------
 * 綜合考點（一致性/交易組）：
 *   1) 訂單狀態機：CREATED→PAID→SHIPPED→DONE / CANCELLED，只走合法轉移。
 *   2) Saga 編排：下單付款拆成 3 步「正向動作」，各自配一個「補償動作」：
 *        Step A 預扣庫存   ←補償→ 釋放庫存
 *        Step B 扣款        ←補償→ 退款
 *        Step C 確認訂單    ←補償→ （取消，回滾至前面補償）
 *      任一步失敗 → 反向（後進先出）執行已完成步驟的補償 → 訂單 CANCELLED，
 *      最終達成一致（庫存退回、款項退回，不留中間態）。
 *   3) 冪等下單：同 idempotency_key 重送 → 回原訂單，不重複扣庫存/扣款。
 *   4) 庫存原子預扣：在 flock 臨界區內「判斷 stock>=qty 才扣」→ 防超賣。
 *
 * 真實系統：訂單/庫存/支付為獨立服務，用訊息佇列做非同步 Saga 編排，
 *   補償透過反向事件驅動；本檔在單機把這套「動作→補償」流程如實跑出來。
 */
final class OrderSaga
{
    private string $stateFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->stateFile = $dataDir . '/order.json';
        if (!file_exists($this->stateFile)) {
            file_put_contents($this->stateFile, json_encode($this->emptyState()));
        }
    }

    private function emptyState(): array
    {
        return [
            'inventory' => [        // 商品庫存（原子預扣對象）
                'sku-1' => ['name' => '無線耳機', 'stock' => 5, 'price' => 1200],
                'sku-2' => ['name' => '機械鍵盤', 'stock' => 3, 'price' => 2800],
            ],
            'wallet'  => 100000,    // 使用者錢包餘額（支付服務模擬）
            'orders'  => [],        // order_id => 訂單（含狀態、saga 記錄）
            'idem'    => [],        // idempotency_key => order_id
            'seq'     => 0,
        ];
    }

    /* ============================================================
     * 原子核心：flock(LOCK_EX) 臨界區內執行 callback($state)
     * callback 回傳 [新狀態|null, 回傳值]；null 表示不寫回。
     * 這是所有「讀-改-寫」的唯一入口，保證並發安全（模擬行鎖）。
     * ============================================================ */
    private function atomic(callable $fn): mixed
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            throw new RuntimeException('cannot open state file');
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $state = json_decode($raw === '' ? '' : (string) $raw, true);
            if (!is_array($state)) {
                $state = $this->emptyState();
            }
            [$newState, $ret] = $fn($state);
            if ($newState !== null) {
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
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function snapshot(): array
    {
        $raw = (string) file_get_contents($this->stateFile);
        $s = json_decode($raw, true);
        return is_array($s) ? $s : $this->emptyState();
    }

    /** 重置 demo 狀態（方便重複實驗） */
    public function reset(): array
    {
        return $this->atomic(fn(array $s) => [$this->emptyState(), ['ok' => true]]);
    }

    /* ============================================================
     * 下單（Saga 編排核心）
     *   參數：user, sku, qty, idemKey, ?failAt（注入失敗：pay|confirm）
     *   流程：建單(CREATED) → A 預扣庫存 → B 扣款(→PAID) → C 確認(→SHIPPED)
     *   失敗：反向補償已完成步驟 → 訂單 CANCELLED
     * 整個 Saga 在單一臨界區內完成（單機模擬「最終一致」的結果）。
     * ============================================================ */
    public function placeOrder(
        string $user,
        string $sku,
        int $qty,
        string $idemKey,
        ?string $failAt = null
    ): array {
        $qty = max(1, $qty);
        return $this->atomic(function (array $s) use ($user, $sku, $qty, $idemKey, $failAt) {

            // (0) 冪等：同 idem_key 已建過單 → 回原訂單，完全不重做
            if ($idemKey !== '' && isset($s['idem'][$idemKey])) {
                $oid = $s['idem'][$idemKey];
                return [null, ($s['orders'][$oid] ?? ['error' => 'idem_dangling'])
                              + ['idempotent' => true]];
            }

            // 基本驗證
            if (!isset($s['inventory'][$sku])) {
                return [null, ['status' => 'rejected', 'error' => 'unknown_sku']];
            }
            $price = (int) $s['inventory'][$sku]['price'];
            $amount = $price * $qty;

            // 建單：初始 CREATED，並記 saga 步驟軌跡（log）供教學觀察
            $s['seq']++;
            $orderId = 'ord_' . str_pad((string) $s['seq'], 5, '0', STR_PAD_LEFT);
            $order = [
                'order_id'   => $orderId,
                'user'       => $user,
                'sku'        => $sku,
                'qty'        => $qty,
                'amount'     => $amount,
                'state'      => OrderStateMachine::CREATED,
                'saga'       => [],   // 每一步的結果（forward / compensate）
                'created_at' => gmdate('c'),
            ];
            $log = function (string $step, string $action, string $result) use (&$order) {
                $order['saga'][] = ['step' => $step, 'action' => $action, 'result' => $result];
            };

            // 已完成步驟堆疊（補償時後進先出）
            $done = [];

            try {
                // ---- Step A：預扣庫存（原子條件扣減，防超賣）----
                $stock = (int) $s['inventory'][$sku]['stock'];
                if ($stock < $qty) {
                    $log('A_reserve_stock', 'forward', 'fail:insufficient_stock');
                    throw new SagaStepException('insufficient_stock');
                }
                $s['inventory'][$sku]['stock'] = $stock - $qty;   // 預扣
                $log('A_reserve_stock', 'forward', "ok:-{$qty}");
                $done[] = 'A';

                // 注入失敗點：扣款前失敗（測試 A 的補償）
                // （failAt='reserve' 不常用，這裡支援 pay/confirm 兩個常見點）

                // ---- Step B：扣款（→ 狀態轉 PAID）----
                if ($failAt === 'pay') {
                    $log('B_charge', 'forward', 'fail:payment_declined(injected)');
                    throw new SagaStepException('payment_declined');
                }
                if ((int) $s['wallet'] < $amount) {
                    $log('B_charge', 'forward', 'fail:insufficient_funds');
                    throw new SagaStepException('insufficient_funds');
                }
                $s['wallet'] = (int) $s['wallet'] - $amount;       // 扣款
                $order['state'] = OrderStateMachine::transition($order['state'], OrderStateMachine::PAID);
                $log('B_charge', 'forward', "ok:-{$amount} → PAID");
                $done[] = 'B';

                // ---- Step C：確認訂單 / 出貨（→ 狀態轉 SHIPPED）----
                if ($failAt === 'confirm') {
                    $log('C_confirm', 'forward', 'fail:warehouse_error(injected)');
                    throw new SagaStepException('warehouse_error');
                }
                $order['state'] = OrderStateMachine::transition($order['state'], OrderStateMachine::SHIPPED);
                $log('C_confirm', 'forward', 'ok → SHIPPED');
                $done[] = 'C';

                // 全部成功 → 落單、記冪等
                $s['orders'][$orderId] = $order;
                if ($idemKey !== '') {
                    $s['idem'][$idemKey] = $orderId;
                }
                return [$s, $order + ['status' => 'ok', 'idempotent' => false]];

            } catch (SagaStepException $e) {
                // ============ 反向補償（後進先出）============
                // 對「已完成的步驟」逐一執行其補償動作，使狀態回到一致。
                foreach (array_reverse($done) as $step) {
                    if ($step === 'C') {
                        // C 的補償：回退出貨確認（這裡 C 失敗時不會進來）
                        $log('C_confirm', 'compensate', 'rollback_ship');
                    } elseif ($step === 'B') {
                        // B 的補償：退款
                        $s['wallet'] = (int) $s['wallet'] + $amount;
                        $log('B_charge', 'compensate', "refund:+{$amount}");
                    } elseif ($step === 'A') {
                        // A 的補償：釋放庫存
                        $s['inventory'][$sku]['stock'] = (int) $s['inventory'][$sku]['stock'] + $qty;
                        $log('A_reserve_stock', 'compensate', "release:+{$qty}");
                    }
                }
                // 訂單轉 CANCELLED（合法轉移：任一狀態皆可 → CANCELLED）
                $order['state'] = OrderStateMachine::transition($order['state'], OrderStateMachine::CANCELLED);
                $order['fail_reason'] = $e->getMessage();
                $log('order', 'finalize', 'CANCELLED');

                // 補償後的訂單仍落單（保留軌跡），並記冪等（重送回同一張已取消單）
                $s['orders'][$orderId] = $order;
                if ($idemKey !== '') {
                    $s['idem'][$idemKey] = $orderId;
                }
                return [$s, $order + ['status' => 'cancelled', 'idempotent' => false]];
            }
        });
    }

    /** 推進已存在訂單的狀態（PAID→SHIPPED→DONE），示範狀態機合法轉移把關 */
    public function advance(string $orderId, string $to): array
    {
        return $this->atomic(function (array $s) use ($orderId, $to) {
            if (!isset($s['orders'][$orderId])) {
                return [null, ['error' => 'order_not_found']];
            }
            $from = $s['orders'][$orderId]['state'];
            if (!OrderStateMachine::canTransition($from, $to)) {
                return [null, ['error' => 'illegal_transition', 'from' => $from, 'to' => $to,
                               'allowed' => OrderStateMachine::nextStates($from)]];
            }
            $s['orders'][$orderId]['state'] = OrderStateMachine::transition($from, $to);
            return [$s, $s['orders'][$orderId] + ['status' => 'ok']];
        });
    }

    public function getOrder(string $orderId): array
    {
        $s = $this->snapshot();
        return $s['orders'][$orderId] ?? ['error' => 'order_not_found'];
    }

    /** 全局狀態快照（庫存、錢包、訂單數），給前端顯示 */
    public function state(): array
    {
        $s = $this->snapshot();
        return [
            'inventory'   => $s['inventory'],
            'wallet'      => (int) $s['wallet'],
            'order_count' => count($s['orders']),
            'orders'      => array_values($s['orders']),
            'states'      => [
                OrderStateMachine::CREATED, OrderStateMachine::PAID,
                OrderStateMachine::SHIPPED, OrderStateMachine::DONE,
                OrderStateMachine::CANCELLED,
            ],
        ];
    }
}

/** Saga 步驟失敗的內部訊號（觸發反向補償） */
final class SagaStepException extends RuntimeException
{
}
