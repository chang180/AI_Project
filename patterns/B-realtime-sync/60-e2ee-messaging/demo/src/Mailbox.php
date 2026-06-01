<?php
declare(strict_types=1);

/**
 * Mailbox — 伺服器端密文信箱（伺服器只看得到密文！）
 * ===============================================
 * 這模擬「中繼伺服器」：它的工作和 ⑦ Messenger 一樣是**存轉訊息**，
 * 差別在於 E2EE 下伺服器**只存密文**：
 *   - 收進來的每一筆只有 { from, to, n(棘輪序號), cipher(hex 密文) }。
 *   - 沒有金鑰、沒有明文 → 伺服器（與任何能讀資料庫的人）都解不開。
 *   - 收件人裝置端用自己的接收鏈金鑰才能解密。
 *
 * 這正是 E2EE 與一般 Messenger 的核心差異：
 *   一般 Messenger：server 看得到明文（可搜尋 / 過濾 / 防濫用）。
 *   E2EE：server 只是「不識內容的郵差」，隱私換取了部分伺服器端功能（取捨見筆記 §8）。
 *
 * 用 JSON 檔模擬訊息庫；信箱依收件人分組，append-only。
 */
final class Mailbox
{
    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/mailbox.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode($this->blank()));
        }
    }

    private function blank(): array
    {
        return ['next_id' => 1, 'messages' => []];
    }

    /**
     * 投遞一則密文。伺服器只經手 from/to/n/cipher，**完全不碰金鑰或明文**。
     * 回傳伺服器存下的記錄（故意原樣回給 demo，證明伺服器只有密文）。
     */
    public function put(string $from, string $to, int $n, string $cipherHex): array
    {
        $db = $this->read();
        $id = (int) $db['next_id']++;
        $rec = [
            'id'       => $id,
            'from'     => $from,
            'to'       => $to,
            'n'        => $n,          // 棘輪序號：收件人據此對齊接收鏈金鑰
            'cipher'   => $cipherHex,  // 只有密文！
            'put_at'   => gmdate('c'),
        ];
        $db['messages'][(string) $id] = $rec;
        $this->write($db);
        return $rec;
    }

    /**
     * 收件匣：取某使用者收到的所有密文（依棘輪序號 n 排序，維持順序）。
     * 回傳的就是「伺服器所見」——全是密文，無明文。
     */
    public function inbox(string $user): array
    {
        $db = $this->read();
        $msgs = array_values(array_filter($db['messages'], fn($m) => $m['to'] === $user));
        usort($msgs, fn($a, $b) => $a['n'] <=> $b['n']);
        return $msgs;
    }

    /** 取單筆密文記錄（demo 展示舊金鑰解不了新訊息時用）。 */
    public function get(int $id): ?array
    {
        $db = $this->read();
        return $db['messages'][(string) $id] ?? null;
    }

    /** 整個信箱（給測試頁顯示「伺服器只見密文」）。 */
    public function all(): array
    {
        $db = $this->read();
        $msgs = array_values($db['messages']);
        usort($msgs, fn($a, $b) => $a['id'] <=> $b['id']);
        return $msgs;
    }

    private function read(): array
    {
        $json = (string) file_get_contents($this->file);
        $db = json_decode($json, true);
        return is_array($db) ? $db + $this->blank() : $this->blank();
    }

    private function write(array $db): void
    {
        file_put_contents(
            $this->file,
            json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
