<?php
declare(strict_types=1);

/**
 * 訊息路由器（核心：查註冊表 → 在線即時投遞 / 離線進佇列）
 * --------------------------------------------------
 * 這是 Messenger 的心臟。送一則訊息時：
 *   1. 持久化訊息（配 per-conversation 序號，保證順序）。
 *   2. 查連線註冊表：收件人現在在哪台 gateway？
 *      - 在線 → 跨機轉發（真實系統走 Redis Pub/Sub 把訊息推到對方那台 gateway，
 *               再由該 gateway 經 WebSocket push 給 client）→ 這裡寫入收件匣。
 *      - 離線 → 進離線佇列，並觸發推播（APNs / FCM）。
 *   3. 收件人 poll 取得；標記已讀後，發送端可看到已讀回條。
 *
 * 真實對應：本類別 = 訊息服務 + Pub/Sub 轉發決策；MessageStore = 訊息庫/佇列；
 *           ConnectionRegistry = Redis 連線註冊表。
 */
final class MessageRouter
{
    public function __construct(
        private ConnectionRegistry $registry,
        private MessageStore $store,
    ) {}

    /**
     * 1:1 送訊息。回傳投遞結果（含序號與投遞路徑），方便 demo 觀察。
     */
    public function send(string $from, string $to, string $body): array
    {
        $conversation = $this->conversationId($from, $to);

        // 1) 先持久化（無論收件人在不在線，訊息都要落地、要有序號）
        $msg = $this->store->append($conversation, $from, $to, $body);

        // 2) 查連線註冊表決定投遞方式
        $gateway = $this->registry->gatewayOf($to);
        if ($gateway !== null) {
            // 在線：跨機轉發 → 寫入收件匣（模擬 WS push）
            $this->store->deliverToInbox($to, $msg['id']);
            $delivery = ['mode' => 'realtime', 'via_gateway' => $gateway];
        } else {
            // 離線：進佇列 + 推播
            $this->store->enqueueOffline($to, $msg['id']);
            $delivery = ['mode' => 'offline_queue', 'push' => 'APNs/FCM'];
        }

        return [
            'message_id'   => $msg['id'],
            'conversation' => $conversation,
            'seq'          => $msg['seq'],   // 單調遞增，保證順序
            'delivery'     => $delivery,
        ];
    }

    /**
     * 收件人上線後呼叫：補收離線佇列（搬進收件匣）。
     * 真實系統：WS 重連成功後，gateway 拉取離線訊息一次性下發。
     */
    public function onConnect(string $user, string $gateway): int
    {
        $this->registry->connect($user, $gateway);
        return $this->store->flushOffline($user);   // 補投遞離線訊息
    }

    /** poll 收件匣（模擬 client 經 WS 被動收訊）。 */
    public function receive(string $user): array
    {
        return $this->store->poll($user);
    }

    /** 已讀回條：收件人標記與某對方的對話為已讀。 */
    public function markRead(string $reader, string $peer): array
    {
        $conversation = $this->conversationId($reader, $peer);
        return $this->store->markRead($conversation, $reader);
    }

    /** 取得某兩人對話的完整歷史（含已讀狀態），供發送端確認回條。 */
    public function history(string $a, string $b): array
    {
        return $this->store->conversation($this->conversationId($a, $b));
    }

    /**
     * 1:1 對話 id：把兩個 user 排序後拼接，雙向同一個 conversation。
     * 真實系統群組對話另有 group_id；群組 fan-out 取捨見筆記 §8。
     */
    private function conversationId(string $a, string $b): string
    {
        $pair = [$a, $b];
        sort($pair);
        return 'c:' . $pair[0] . ':' . $pair[1];
    }
}
