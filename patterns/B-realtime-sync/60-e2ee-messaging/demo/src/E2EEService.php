<?php
declare(strict_types=1);

/**
 * E2EEService — 端到端加密訊息流程編排（demo 的心臟）
 * ================================================
 * 把四個元件串起來，呈現 E2EE 的完整生命週期：
 *
 *   1. 註冊身分          register()    裝置產生 DH 私鑰 → 公鑰上傳金鑰伺服器（私鑰留本機）
 *   2. 建立加密 session   openSession() 領對方公鑰 → DH 算共享祕密 → KDF 導根金鑰 → 開棘輪
 *   3. 加密發送          send()        裝置端推進發送棘輪取金鑰 → 加密 → 只把密文交給伺服器
 *   4. 收取並解密        receive()     從伺服器領密文 → 裝置端推進接收棘輪取金鑰 → 解密
 *
 * 對應真實 Signal Protocol：
 *   - register/openSession ≈ X3DH（用身分金鑰 + 預付金鑰做初始金鑰協商）。
 *   - send/receive 的棘輪推進 ≈ Double Ratchet 的對稱鏈棘輪（前向保密）。
 *   - 伺服器（Mailbox）只中繼密文 ≈ Signal 伺服器只存密文。
 */
final class E2EEService
{
    public function __construct(
        private KeyServer   $keys,
        private DeviceStore $devices,
        private Mailbox     $mailbox,
    ) {}

    /**
     * 步驟 1：使用者在裝置上註冊身分金鑰。
     *   - 裝置端產生 DH 私鑰（留本機）與公鑰。
     *   - 公鑰上傳金鑰伺服器（伺服器只拿得到公鑰）。
     */
    public function register(string $user): array
    {
        $priv = ToyCrypto::dhPrivateKey();
        $pub  = ToyCrypto::dhPublicKey($priv);
        $this->devices->setIdentityPrivate($user, $priv);   // 私鑰：只留裝置端
        $this->keys->publish($user, $pub);                  // 公鑰：上傳伺服器
        return [
            'user'        => $user,
            'public_key'  => $pub,        // 可公開
            'fingerprint' => $this->keys->fingerprint($user),
            // 注意：私鑰不回傳、不出裝置（這裡僅為 demo 才在 device 檔可見）
        ];
    }

    /**
     * 步驟 2：在 user 的裝置上，與 peer 建立加密 session。
     *   - 領 peer 的公鑰；用「自己的私鑰 + peer 公鑰」算 DH 共享祕密。
     *   - 因 DH 對稱性：user 與 peer 兩邊算出的共享祕密**相同** → KDF 導出相同根金鑰。
     *   - 用根金鑰初始化棘輪。
     * 回傳共享祕密與根金鑰指紋，方便 demo 驗證「雙方算出一樣」。
     */
    public function openSession(string $user, string $peer): array
    {
        $ownPriv  = $this->devices->identityPrivate($user);
        $peerPub  = $this->keys->publicKeyOf($peer);
        if ($ownPriv === null || $peerPub === null) {
            return ['error' => "雙方都要先 register（user 或 peer 尚未發布公鑰）"];
        }
        $shared = ToyCrypto::dhSharedSecret($peerPub, $ownPriv);
        $root   = ToyCrypto::kdf($shared, 'session');
        $this->devices->initSession($user, $peer, $root);
        return [
            'user'           => $user,
            'peer'           => $peer,
            'shared_secret'  => $shared,                 // demo：兩邊應一致
            'root_key_tip'   => substr($root, 0, 12),    // demo：兩邊應一致
            'peer_fingerprint' => $this->keys->fingerprint($peer),
        ];
    }

    /**
     * 步驟 3：加密發送一則訊息。
     *   - 裝置端推進「發送棘輪」取出這則的訊息金鑰（用完即推進，前向保密）。
     *   - 用該金鑰加密 → 得到密文。
     *   - **只把密文 + 序號 n 交給伺服器**（伺服器看不到明文與金鑰）。
     */
    public function send(string $from, string $to, string $plaintext): array
    {
        if (!$this->devices->hasSession($from, $to)) {
            return ['error' => "$from 尚未與 $to 建立 session（請先 openSession）"];
        }
        [$mk, $n] = $this->devices->nextSendKey($from, $to);   // 推進發送棘輪
        $cipher   = ToyCrypto::encrypt($plaintext, $mk);       // 教學示意 XOR 加密
        $rec      = $this->mailbox->put($from, $to, $n, $cipher); // 伺服器只收密文
        return [
            'ok'             => true,
            'n'              => $n,
            'plaintext'      => $plaintext,   // demo 顯示：發送端本地知道明文
            'server_sees'    => [             // demo 顯示：伺服器只看到這些（無明文）
                'from'   => $rec['from'],
                'to'     => $rec['to'],
                'n'      => $rec['n'],
                'cipher' => $rec['cipher'],
            ],
            'message_key_tip' => substr($mk, 0, 12),
        ];
    }

    /**
     * 步驟 4：收取並解密。
     *   - 從伺服器領出所有寄給自己的密文。
     *   - 對每一則，推進「接收棘輪」取出對應序號的訊息金鑰 → 解密還原明文。
     *   - 收完後接收鏈已推進，**先前那些訊息的金鑰被覆蓋**（前向保密）。
     */
    public function receive(string $user, string $peer): array
    {
        $inbox = $this->mailbox->inbox($user);
        $out = [];
        foreach ($inbox as $m) {
            if ($m['from'] !== $peer) {
                continue;
            }
            $recvN = $this->devices->sessionInfo($user, $peer)['recv_n'] ?? 0;
            if ($m['n'] < $recvN) {
                // 這則之前已收過、金鑰已被棘輪覆蓋 → 跳過（避免重算舊金鑰）
                continue;
            }
            [$mk, ] = $this->devices->nextRecvKey($user, $peer);  // 推進接收棘輪
            $plain = ToyCrypto::decrypt($m['cipher'], $mk);
            $out[] = [
                'n'         => $m['n'],
                'from'      => $m['from'],
                'cipher'    => $m['cipher'],   // 伺服器存的密文
                'decrypted' => $plain,         // 裝置端解出的明文
            ];
        }
        return $out;
    }

    /**
     * demo 專用：示範「前向保密」——拿『舊的接收金鑰狀態』去解『新訊息』會失敗。
     * 收件人接收鏈已推進到 recv_n；若硬要用「更舊序號」的金鑰解新密文，會得到亂碼。
     * 這裡示意：用「已被棘輪覆蓋的舊金鑰」解不出後續訊息。
     */
    public function demoForwardSecrecy(string $user, string $peer): array
    {
        $info = $this->devices->sessionInfo($user, $peer);
        if ($info === null) {
            return ['error' => '尚無 session'];
        }
        $recvN = $info['recv_n'];
        if ($recvN < 1) {
            return ['note' => '請先 receive 至少一則訊息，接收棘輪才會推進'];
        }
        // 取信箱中一則「已被收過」的舊訊息（n < recvN）
        $inbox = $this->mailbox->inbox($user);
        $oldMsg = null;
        foreach ($inbox as $m) {
            if ($m['from'] === $peer && $m['n'] < $recvN) {
                $oldMsg = $m;
                break;
            }
        }
        if ($oldMsg === null) {
            return ['note' => '尚無「已收過的舊訊息」可示範'];
        }
        // 嘗試用「目前（已推進的）接收鏈」去解這則舊訊息 → 金鑰已對不上 → 亂碼
        $wrongKey = $this->devices->peekRecvKeyAt($user, $peer, $oldMsg['n']);
        $garbled  = $wrongKey === null
            ? '【金鑰已被棘輪銷毀，無法重建】'
            : ToyCrypto::decrypt($oldMsg['cipher'], $wrongKey);
        return [
            'explain'      => '接收棘輪已推進到 recv_n=' . $recvN
                            . '，序號 ' . $oldMsg['n'] . ' 的舊金鑰已被單向 hash 覆蓋、算不回來，'
                            . '所以即使密文還在，也無法重新解出 → 前向保密。',
            'old_message_n' => $oldMsg['n'],
            'cipher'        => $oldMsg['cipher'],
            'recover_attempt' => $garbled,   // null/亂碼，證明舊金鑰救不回來
        ];
    }
}
