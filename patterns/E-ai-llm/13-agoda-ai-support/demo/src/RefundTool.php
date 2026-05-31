<?php
declare(strict_types=1);

/**
 * 退款工具 + Guardrail 護欄（本題精華：會動錢的工具如何安全）
 * --------------------------------------------------
 * 真實系統：Agent 透過 function calling 提議呼叫 create_refund，但「准不准」由確定性護欄決定，
 * 不交給可被話術（prompt injection）的 LLM。護欄在程式碼層，使用者就算說服 LLM「忽略規則」也無效。
 *
 * 護欄規則：
 *   1) 訂單必須存在且狀態可退（不可退房型 / 已退 → 擋）。
 *   2) 金額上限：≤ NT$2000 自動核准；> 上限轉人工審核。
 *   3) 冪等：同一訂單的退款以 idempotency_key 去重，重複呼叫不會退兩次。
 *   4) 每個決策（過 / 不過 / 轉人工）都寫審計日誌。
 *
 * 儲存：用 JSON 檔模擬交易型 DB（orders 唯讀示意、refunds 冪等、audit append-only）。
 */
final class RefundTool
{
    /** 自動退款金額上限（超過轉人工） */
    private const AUTO_LIMIT = 2000;

    private string $refundsFile;
    private string $auditFile;

    /** @var array<string,array{order_id:string,amount:int,status:string,refundable:bool}> 訂單（示意，唯讀） */
    private array $orders;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->refundsFile = $dataDir . '/refunds.json';
        $this->auditFile = $dataDir . '/audit.log';
        if (!file_exists($this->refundsFile)) {
            file_put_contents($this->refundsFile, '{}');
        }

        // 示意訂單表（真實系統來自交易型 DB）
        $this->orders = [
            'BK-1001' => ['order_id' => 'BK-1001', 'amount' => 1200, 'status' => 'paid',          'refundable' => true],
            'BK-2002' => ['order_id' => 'BK-2002', 'amount' => 8800, 'status' => 'paid',          'refundable' => true],
            'BK-3003' => ['order_id' => 'BK-3003', 'amount' => 1500, 'status' => 'non_refundable', 'refundable' => false],
        ];
    }

    /** 工具：查訂單（Agent 觀察用） */
    public function getOrder(string $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }

    /**
     * 工具：發起退款（通過 Guardrail 才放行）。
     * @return array{decision:string,reason:string,amount?:int,refund_id?:string,idempotent?:bool}
     */
    public function createRefund(string $orderId): array
    {
        // 護欄 1：訂單存在 + 可退
        $order = $this->orders[$orderId] ?? null;
        if ($order === null) {
            return $this->audit($orderId, 'reject', '訂單不存在', 0);
        }
        if (!$order['refundable'] || $order['status'] === 'non_refundable') {
            return $this->audit($orderId, 'reject', '此房型不可退款（政策 §2）', $order['amount']);
        }

        $amount = $order['amount'];
        $idemKey = $this->idempotencyKey($orderId, $amount);

        // 護欄 3：冪等 — 已退過直接回原結果，不重複退
        $refunds = $this->readRefunds();
        if (isset($refunds[$idemKey])) {
            $prev = $refunds[$idemKey];
            $this->audit($orderId, 'idempotent_hit', '冪等鍵命中，回傳既有退款，不重複退款', $amount);
            return [
                'decision' => $prev['decision'],
                'reason' => '此訂單已退款（冪等保護，未重複退）',
                'amount' => $amount,
                'refund_id' => $prev['refund_id'],
                'idempotent' => true,
            ];
        }

        // 護欄 2：金額上限 — 超過轉人工審核
        if ($amount > self::AUTO_LIMIT) {
            $res = $this->audit($orderId, 'manual_review',
                '金額 NT$' . $amount . ' 超過自動上限 NT$' . self::AUTO_LIMIT . '，轉人工審核（政策 §3）', $amount);
            // 轉人工也要記錄一筆 pending，避免 LLM 反覆重試
            $refunds[$idemKey] = [
                'refund_id' => 'RF-' . substr($idemKey, 0, 8),
                'order_id' => $orderId, 'amount' => $amount,
                'decision' => 'manual_review', 'created_at' => gmdate('c'),
            ];
            $this->writeRefunds($refunds);
            return $res + ['amount' => $amount, 'refund_id' => $refunds[$idemKey]['refund_id']];
        }

        // 全部護欄通過 → 自動退款
        $refundId = 'RF-' . substr($idemKey, 0, 8);
        $refunds[$idemKey] = [
            'refund_id' => $refundId, 'order_id' => $orderId, 'amount' => $amount,
            'decision' => 'refunded', 'created_at' => gmdate('c'),
        ];
        $this->writeRefunds($refunds);
        $res = $this->audit($orderId, 'refunded', '小額自動退款核准（NT$' . $amount . '）', $amount);
        return $res + ['amount' => $amount, 'refund_id' => $refundId];
    }

    /** 讀取審計日誌（測試頁顯示用） */
    public function auditTail(int $n = 20): array
    {
        if (!file_exists($this->auditFile)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($this->auditFile)));
        return array_slice($lines, -$n);
    }

    /** 冪等鍵：同一訂單 + 金額 → 同一鍵（真實系統可由客戶端帶入更嚴謹的鍵） */
    private function idempotencyKey(string $orderId, int $amount): string
    {
        return hash('sha256', $orderId . '|' . $amount);
    }

    /**
     * 寫審計日誌（append-only），並回傳決策結果。
     * @return array{decision:string,reason:string}
     */
    private function audit(string $orderId, string $decision, string $reason, int $amount): array
    {
        $line = json_encode([
            'ts' => gmdate('c'),
            'actor' => 'agent',
            'action' => 'create_refund',
            'order_id' => $orderId,
            'amount' => $amount,
            'decision' => $decision,
            'reason' => $reason,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->auditFile, $line . "\n", FILE_APPEND | LOCK_EX);
        return ['decision' => $decision, 'reason' => $reason];
    }

    private function readRefunds(): array
    {
        $json = (string) file_get_contents($this->refundsFile);
        return json_decode($json, true) ?: [];
    }

    private function writeRefunds(array $refunds): void
    {
        file_put_contents(
            $this->refundsFile,
            json_encode($refunds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
