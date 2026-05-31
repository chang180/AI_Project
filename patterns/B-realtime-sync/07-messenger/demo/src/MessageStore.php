<?php
declare(strict_types=1);

/**
 * 訊息持久化 + 收件匣 + 離線佇列（模擬訊息庫 + 投遞通道）
 * --------------------------------------------------
 * 真實系統拆成幾塊：
 *   - 訊息庫（messages）：append-only，依 conversation 分片儲存，永久保存歷史。
 *   - 收件匣（inbox）：在線投遞時寫入；真實系統由收件人那台 WS Gateway 直接 push，
 *                      這裡用 HTTP poll 取代「server push」，邏輯等價。
 *   - 離線佇列（offline_queue）：收件人不在線時暫存，待其上線再補投遞，
 *                      並觸發推播（APNs / FCM）通知。
 *   - per-conversation 序號：保證同一對話內訊息單調遞增、不亂序。
 *
 * 這裡全部存在一個 JSON 檔，用檔案鎖確保並發安全。
 */
final class MessageStore
{
    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/messages.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode($this->blank()));
        }
    }

    private function blank(): array
    {
        return [
            'seq'      => [],   // conversation_id => 目前最大序號
            'messages' => [],   // 全量訊息（持久化歷史）
            'inbox'    => [],   // user => [ message_id, ... ] 已投遞、待 poll
            'offline'  => [],   // user => [ message_id, ... ] 離線暫存
            'push_log' => [],   // 推播紀錄（模擬 APNs / FCM）
            'next_id'  => 1,    // 全域訊息流水號
        ];
    }

    /**
     * 取得 conversation 的下一個序號（單調遞增，保證 per-conversation 順序）。
     * 真實系統可由 Redis INCR 或資料庫 sequence 提供。
     */
    private function nextSeq(array &$db, string $conversation): int
    {
        $cur = (int) ($db['seq'][$conversation] ?? 0);
        $cur++;
        $db['seq'][$conversation] = $cur;
        return $cur;
    }

    /**
     * 持久化一則新訊息，回傳完整訊息記錄（含序號）。
     * 投遞與否由 MessageRouter 決定，這裡只負責「寫入訊息庫 + 配序號」。
     */
    public function append(string $conversation, string $from, string $to, string $body): array
    {
        return $this->mutate(function (array &$db) use ($conversation, $from, $to, $body): array {
            $seq = $this->nextSeq($db, $conversation);
            $id  = (int) $db['next_id']++;
            $msg = [
                'id'           => $id,
                'conversation' => $conversation,
                'seq'          => $seq,
                'from'         => $from,
                'to'           => $to,
                'body'         => $body,
                'sent_at'      => gmdate('c'),
                'read'         => false,   // 已讀回條：收件人標記後變 true
            ];
            $db['messages'][(string) $id] = $msg;
            return $msg;
        });
    }

    /** 在線即時投遞：寫入收件人 inbox（真實系統為 WS push）。 */
    public function deliverToInbox(string $user, int $messageId): void
    {
        $this->mutate(function (array &$db) use ($user, $messageId): void {
            $db['inbox'][$user][] = $messageId;
        });
    }

    /** 離線暫存：寫入離線佇列，並記一筆推播（APNs / FCM）。 */
    public function enqueueOffline(string $user, int $messageId): void
    {
        $this->mutate(function (array &$db) use ($user, $messageId): void {
            $db['offline'][$user][] = $messageId;
            $db['push_log'][] = [
                'to'      => $user,
                'message' => $messageId,
                'channel' => 'APNs/FCM',
                'at'      => gmdate('c'),
            ];
        });
    }

    /**
     * 上線補收：把離線佇列搬進收件匣（依序號排序，維持順序），清空離線佇列。
     */
    public function flushOffline(string $user): int
    {
        return $this->mutate(function (array &$db) use ($user): int {
            $pending = $db['offline'][$user] ?? [];
            if (!$pending) {
                return 0;
            }
            usort($pending, fn($a, $b) => ($db['messages'][(string)$a]['seq'] ?? 0)
                                        <=> ($db['messages'][(string)$b]['seq'] ?? 0));
            foreach ($pending as $mid) {
                $db['inbox'][$user][] = $mid;
            }
            $db['offline'][$user] = [];
            return count($pending);
        });
    }

    /**
     * poll 收件匣：取出並清空 inbox，回傳訊息（依序號排序）。
     * 真實系統 client 由 WS 連線被動接收，這裡用主動 poll 取代。
     */
    public function poll(string $user): array
    {
        return $this->mutate(function (array &$db) use ($user): array {
            $ids = $db['inbox'][$user] ?? [];
            $db['inbox'][$user] = [];
            $msgs = array_map(fn($id) => $db['messages'][(string) $id], $ids);
            usort($msgs, fn($a, $b) => $a['seq'] <=> $b['seq']);
            return $msgs;
        });
    }

    /**
     * 已讀回條：收件人把某對話的訊息標記為已讀。
     * 回傳被標記的訊息 id（供發送端看到回條）。
     */
    public function markRead(string $conversation, string $reader): array
    {
        return $this->mutate(function (array &$db) use ($conversation, $reader): array {
            $marked = [];
            foreach ($db['messages'] as $id => &$m) {
                if ($m['conversation'] === $conversation && $m['to'] === $reader && !$m['read']) {
                    $m['read'] = true;
                    $marked[] = (int) $id;
                }
            }
            unset($m);
            return $marked;
        });
    }

    /** 取得某對話完整歷史（依序號排序），給發送端確認順序與已讀狀態。 */
    public function conversation(string $conversation): array
    {
        $db = $this->read();
        $msgs = array_values(array_filter(
            $db['messages'],
            fn($m) => $m['conversation'] === $conversation
        ));
        usort($msgs, fn($a, $b) => $a['seq'] <=> $b['seq']);
        return $msgs;
    }

    public function offlineCount(string $user): int
    {
        return count($this->read()['offline'][$user] ?? []);
    }

    public function pushLog(): array
    {
        return $this->read()['push_log'] ?? [];
    }

    // ---- 底層讀寫（檔案鎖確保並發安全）----

    private function read(): array
    {
        $json = (string) file_get_contents($this->file);
        $db = json_decode($json, true);
        return is_array($db) ? $db + $this->blank() : $this->blank();
    }

    /** 以排他鎖讀-改-寫，回傳 callback 的結果。 */
    private function mutate(callable $fn): mixed
    {
        $fp = fopen($this->file, 'c+');
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $db = json_decode((string) $raw, true);
        $db = is_array($db) ? $db + $this->blank() : $this->blank();

        $result = $fn($db);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $result;
    }
}
