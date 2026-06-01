<?php
declare(strict_types=1);

/**
 * DeviceStore — 裝置端金鑰狀態（私鑰 + 棘輪狀態，永不上伺服器）
 * =========================================================
 * 這模擬「使用者裝置本機」的安全儲存區。E2EE 的核心切分：
 *   - 私鑰、共享祕密、棘輪鏈金鑰 → **只存在裝置端**（這個檔模擬本機 keystore）。
 *   - 伺服器只存「公鑰」(KeyServer) 與「密文」(Mailbox)，看不到這裡任何東西。
 *
 * 每個 (user → peer) 對話維護一套棘輪狀態：
 *   - identity_priv：自己的身分私鑰（DH 私鑰）。
 *   - send_chain  ：發送鏈金鑰；每發一則就 ratchet 推進一次。
 *   - recv_chain  ：接收鏈金鑰；每收一則就 ratchet 推進一次（與對方發送鏈同步）。
 *   - send_n / recv_n：已推進次數（訊息序號，讓雙方對齊金鑰）。
 *
 * ⚠️ 真實系統用 Double Ratchet：除了這條「對稱鏈棘輪」，還疊一層「DH 棘輪」
 *    （每輪換新的 DH 金鑰對），達成前向保密 + 後向保密。本 demo 只示意對稱鏈棘輪。
 *
 * 用 JSON 檔模擬，並用前綴把「不同 user 的本機狀態」隔開（示意各自裝置）。
 */
final class DeviceStore
{
    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/devices.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, '{}');
        }
    }

    /** 存自己的身分私鑰（裝置產生後保存，永不外傳）。 */
    public function setIdentityPrivate(string $user, int $priv): void
    {
        $all = $this->read();
        $all[$user]['identity_priv'] = $priv;
        $this->write($all);
    }

    public function identityPrivate(string $user): ?int
    {
        $all = $this->read();
        return isset($all[$user]['identity_priv']) ? (int) $all[$user]['identity_priv'] : null;
    }

    /**
     * 初始化與某 peer 的棘輪：用 DH 共享祕密經 KDF 得到根金鑰，
     * 發送鏈與接收鏈都從同一把根金鑰起算（雙方對稱，故能對齊）。
     */
    public function initSession(string $user, string $peer, string $rootKey): void
    {
        $all = $this->read();
        $all[$user]['sessions'][$peer] = [
            'root'       => $rootKey,
            'send_chain' => $rootKey,
            'recv_chain' => $rootKey,
            'send_n'     => 0,
            'recv_n'     => 0,
        ];
        $this->write($all);
    }

    public function hasSession(string $user, string $peer): bool
    {
        $all = $this->read();
        return isset($all[$user]['sessions'][$peer]);
    }

    /**
     * 取「下一則發送金鑰」並推進發送鏈：
     *   messageKey = MK(send_chain)，然後 send_chain = ratchet(send_chain)、send_n++。
     * 回傳 [messageKey, 這則的序號 n]。推進後舊鏈金鑰被覆蓋（前向保密）。
     */
    public function nextSendKey(string $user, string $peer): array
    {
        $all = $this->read();
        $s =& $all[$user]['sessions'][$peer];
        $n = (int) $s['send_n'];
        $mk = ToyCrypto::messageKey($s['send_chain']);
        $s['send_chain'] = ToyCrypto::ratchet($s['send_chain']); // 單向推進，舊金鑰算不出新的
        $s['send_n'] = $n + 1;
        $this->write($all);
        return [$mk, $n];
    }

    /**
     * 取「下一則接收金鑰」並推進接收鏈（與對方發送鏈同步演進）。
     * 回傳 [messageKey, 序號 n]。
     */
    public function nextRecvKey(string $user, string $peer): array
    {
        $all = $this->read();
        $s =& $all[$user]['sessions'][$peer];
        $n = (int) $s['recv_n'];
        $mk = ToyCrypto::messageKey($s['recv_chain']);
        $s['recv_chain'] = ToyCrypto::ratchet($s['recv_chain']);
        $s['recv_n'] = $n + 1;
        $this->write($all);
        return [$mk, $n];
    }

    /**
     * 「窺探」某序號的訊息金鑰，但**不推進**鏈（給 demo 展示前向保密用）：
     * 從目前 recv_chain 往後推 (target - recv_n) 次，算出那把金鑰。
     * 若 target < recv_n（已經推進過、舊金鑰已銷毀）→ 回 null：算不出舊金鑰。
     */
    public function peekRecvKeyAt(string $user, string $peer, int $target): ?string
    {
        $all = $this->read();
        $s = $all[$user]['sessions'][$peer] ?? null;
        if ($s === null) {
            return null;
        }
        $cur = (int) $s['recv_n'];
        if ($target < $cur) {
            return null; // 舊金鑰已被棘輪覆蓋，前向保密：再也算不出來
        }
        $chain = $s['recv_chain'];
        for ($i = $cur; $i < $target; $i++) {
            $chain = ToyCrypto::ratchet($chain);
        }
        return ToyCrypto::messageKey($chain);
    }

    /** 回傳某 user 與某 peer 的棘輪狀態摘要（給測試頁顯示推進情形）。 */
    public function sessionInfo(string $user, string $peer): ?array
    {
        $all = $this->read();
        $s = $all[$user]['sessions'][$peer] ?? null;
        if ($s === null) {
            return null;
        }
        return [
            'send_n'         => (int) $s['send_n'],
            'recv_n'         => (int) $s['recv_n'],
            'send_chain_tip' => substr($s['send_chain'], 0, 12),
            'recv_chain_tip' => substr($s['recv_chain'], 0, 12),
        ];
    }

    private function read(): array
    {
        $json = (string) file_get_contents($this->file);
        return json_decode($json, true) ?: [];
    }

    private function write(array $all): void
    {
        file_put_contents(
            $this->file,
            json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
