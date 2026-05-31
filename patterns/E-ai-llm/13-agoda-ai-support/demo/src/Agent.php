<?php
declare(strict_types=1);

/**
 * Agent（Orchestrator）— 規則式「LLM」決策
 * --------------------------------------------------
 * 真實系統：把對話 + 工具 schema 丟給 LLM，LLM 以 function calling 決定「回答 FAQ」或「呼叫工具」，
 * 觀察工具結果後再決策，直到能回答為止（有最大迭代上限）。
 * 這裡用關鍵字 if/else 模擬這段決策，以呈現「RAG 帶引用」與「Agent 工具呼叫迴圈」的真實流程；
 * RAG 檢索（Retriever）、工具呼叫、退款護欄（RefundTool）皆為真實邏輯。
 *
 * 決策分支：
 *   - 訊息含「退 / 取消 + 訂單編號」→ 走工具：先 get_order 觀察，再 create_refund（過 Guardrail）。
 *   - 其他問句 → 走 RAG：檢索 top-k；分數夠高 → 帶引用回答；分數過低 → 誠實拒答 + 轉人工（防幻覺）。
 */
final class Agent
{
    /** RAG 檢索分數門檻：低於此值不硬答，避免幻覺政策 */
    private const MIN_SCORE = 0.34;

    public function __construct(
        private Retriever $retriever,
        private RefundTool $refundTool,
    ) {}

    /**
     * 處理一輪對話，回傳結構化結果（供測試頁呈現對話/來源/工具呼叫）。
     * @return array{
     *   reply:string,
     *   route:string,
     *   sources:list<array{doc:string,section:string,score:float}>,
     *   tool_calls:list<array<string,mixed>>
     * }
     */
    public function handle(string $message): array
    {
        $orderId = $this->extractOrderId($message);
        $wantsRefund = $this->mentionsRefund($message);
        $isPolicyQuestion = $this->looksLikePolicyQuestion($message);

        // ---- 動作意圖：呼叫工具（Agent 工具呼叫迴圈）----
        // 帶訂單編號 → 直接執行退款流程（即使語句像問句，使用者意圖明確要退這筆）。
        if ($wantsRefund && $orderId !== null) {
            return $this->runRefundFlow($orderId);
        }

        // 想退款、無訂單編號、且非「政策詢問」→ 多輪對話：要求澄清訂單編號。
        // （「退款政策」「可以退款嗎」這類詢問會落到下方 RAG，帶引用回答，而非要求訂單編號。）
        if ($wantsRefund && !$isPolicyQuestion) {
            return [
                'reply' => '好的，我可以協助退款。請提供您的訂單編號（例如 BK-1001），我會立即為您查詢。',
                'route' => 'clarify',
                'sources' => [],
                'tool_calls' => [],
            ];
        }

        // ---- 知識問答意圖：RAG 帶引用 ----
        return $this->runRagFlow($message);
    }

    /** 工具路徑：get_order 觀察 → create_refund（護欄）→ 依結果生成回覆 */
    private function runRefundFlow(string $orderId): array
    {
        $toolCalls = [];

        // 第一步：查訂單（觀察）
        $order = $this->refundTool->getOrder($orderId);
        $toolCalls[] = ['name' => 'get_order', 'args' => ['order_id' => $orderId], 'result' => $order ?? ['error' => 'not_found']];

        if ($order === null) {
            return [
                'reply' => "查無訂單 {$orderId}，請確認訂單編號是否正確。",
                'route' => 'tool',
                'sources' => [],
                'tool_calls' => $toolCalls,
            ];
        }

        // 第二步：發起退款（過 Guardrail）
        $result = $this->refundTool->createRefund($orderId);
        $toolCalls[] = ['name' => 'create_refund', 'args' => ['order_id' => $orderId], 'result' => $result];

        $reply = match ($result['decision']) {
            'refunded' => isset($result['idempotent']) && $result['idempotent']
                ? "訂單 {$orderId} 先前已退款（退款編號 {$result['refund_id']}），系統以冪等保護避免重複退款。"
                : "已為訂單 {$orderId} 完成退款 NT\${$result['amount']}（退款編號 {$result['refund_id']}），款項約 7 個工作天退回原付款方式。",
            'manual_review' => "訂單 {$orderId} 退款金額 NT\${$result['amount']} 超過自動核准上限，已為您轉人工審核（案件 {$result['refund_id']}），客服將儘速處理。",
            'reject' => "很抱歉，訂單 {$orderId} 無法退款：{$result['reason']}。",
            default => "退款處理結果：{$result['reason']}。",
        };

        return [
            'reply' => $reply,
            'route' => 'tool',
            'sources' => [],
            'tool_calls' => $toolCalls,
        ];
    }

    /** RAG 路徑：檢索 → 低分拒答 / 高分帶引用回答 */
    private function runRagFlow(string $message): array
    {
        $hits = $this->retriever->search($message, 2);

        if ($hits === [] || $hits[0]['score'] < self::MIN_SCORE) {
            // 防幻覺：檢索不到可靠依據 → 誠實拒答 + 轉人工
            return [
                'reply' => '這個問題我在政策知識庫中找不到足夠依據，為避免提供錯誤資訊，已為您轉接真人客服。',
                'route' => 'rag_low_confidence',
                'sources' => array_map($this->sourceView(...), $hits),
                'tool_calls' => [],
            ];
        }

        // 帶引用回答（只根據檢索片段，並標註來源）
        $top = $hits[0];
        $reply = $top['text'] . "（來源：{$top['doc']} {$top['section']}）";

        return [
            'reply' => $reply,
            'route' => 'rag',
            'sources' => array_map($this->sourceView(...), $hits),
            'tool_calls' => [],
        ];
    }

    /** @return array{doc:string,section:string,score:float} */
    private function sourceView(array $hit): array
    {
        return ['doc' => $hit['doc'], 'section' => $hit['section'], 'score' => $hit['score']];
    }

    /** 從訊息抽出訂單編號（BK-1234 格式） */
    private function extractOrderId(string $message): ?string
    {
        if (preg_match('/\bBK-?\d{3,}\b/i', $message, $m)) {
            $id = strtoupper($m[0]);
            return str_contains($id, '-') ? $id : preg_replace('/^BK/', 'BK-', $id);
        }
        return null;
    }

    /** 是否為「政策詢問」而非「執行動作」（含問句標記或政策字眼） */
    private function looksLikePolicyQuestion(string $message): bool
    {
        foreach (['政策', '規定', '條件', '可以', '能不能', '怎麼', '如何', '嗎', '?', '？'] as $kw) {
            if (strpos($message, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /** 是否為退款/取消意圖 */
    private function mentionsRefund(string $message): bool
    {
        // 註：環境未必載入 mbstring；UTF-8 為自同步編碼，中文關鍵字以位元組子字串比對即可正確命中。
        foreach (['退款', '退錢', '退訂', '取消', 'refund', 'cancel'] as $kw) {
            if (stripos($message, $kw) !== false) {
                return true;
            }
        }
        return false;
    }
}
