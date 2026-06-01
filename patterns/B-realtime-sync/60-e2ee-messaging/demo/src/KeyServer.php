<?php
declare(strict_types=1);

/**
 * KeyServer — 金鑰伺服器（發布公鑰目錄）
 * ====================================
 * E2EE 的第一步：每個使用者產生一對「身分金鑰」(identity key)，
 * 把「公鑰」上傳到金鑰伺服器；要跟對方建立加密 session 時，先來這裡領對方公鑰。
 *
 * 關鍵安全模型：
 *   - 伺服器**只存公鑰**，永遠看不到任何人的私鑰（私鑰只留在裝置端）。
 *   - 因此伺服器（與任何竊聽者）即使握有全部公鑰，也算不出共享祕密 → 解不了訊息。
 *   - 真實系統還會發布「一次性預付金鑰」(prekey) 讓對方離線也能先建 session（X3DH），
 *     並對公鑰做指紋驗證（safety number）以防中間人攻擊（見筆記 §6）。
 *
 * 本檔用 JSON 檔模擬金鑰目錄（真實系統為一個高可用的金鑰服務）。
 */
final class KeyServer
{
    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/keys.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, '{}');
        }
    }

    /**
     * 發布某使用者的身分公鑰（裝置端產生私鑰後，只上傳公鑰）。
     * 回傳該使用者的公鑰（DH 公開值）。
     */
    public function publish(string $user, int $publicKey): int
    {
        $dir = $this->read();
        $dir[$user] = ['public_key' => $publicKey, 'updated_at' => gmdate('c')];
        $this->write($dir);
        return $publicKey;
    }

    /** 領取某使用者的公鑰（建立 session 時用）；不存在回 null。 */
    public function publicKeyOf(string $user): ?int
    {
        $dir = $this->read();
        return isset($dir[$user]) ? (int) $dir[$user]['public_key'] : null;
    }

    /** 公鑰指紋（safety number 的示意）：雙方比對一致即可確認沒有中間人。 */
    public function fingerprint(string $user): ?string
    {
        $pk = $this->publicKeyOf($user);
        return $pk === null ? null : substr(hash('sha256', 'fp|' . $user . '|' . $pk), 0, 12);
    }

    /** 整個公鑰目錄（給測試頁顯示，證明伺服器只有公鑰、沒有私鑰）。 */
    public function directory(): array
    {
        $out = [];
        foreach ($this->read() as $user => $rec) {
            $out[$user] = [
                'public_key'  => (int) $rec['public_key'],
                'fingerprint' => $this->fingerprint($user),
            ];
        }
        return $out;
    }

    private function read(): array
    {
        $json = (string) file_get_contents($this->file);
        return json_decode($json, true) ?: [];
    }

    private function write(array $dir): void
    {
        file_put_contents(
            $this->file,
            json_encode($dir, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
