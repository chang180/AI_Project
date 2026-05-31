<?php
declare(strict_types=1);

/**
 * 連線註冊表（模擬 Redis 的 user → gateway 對映 + presence）
 * --------------------------------------------------
 * 真實系統：億級長連線（WebSocket）分散在數千台 WS Gateway 上。
 *   要把訊息送給某個 user，必須先知道「他現在連在哪一台 gateway」。
 *   這份對映存在 Redis：  key = user:{id}  →  value = { gateway, last_seen }
 *   presence（在線狀態）= 靠心跳更新 last_seen，超過 TTL 即視為離線。
 *
 * 這裡用 JSON 檔模擬 Redis，呈現「註冊 / 查詢 / presence / 心跳 / 下線」邏輯。
 */
final class ConnectionRegistry
{
    /** presence TTL：超過此秒數沒心跳即視為離線（真實系統常設 30~60 秒） */
    private const TTL_SECONDS = 60;

    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/connections.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, '{}');
        }
    }

    /**
     * 上線：把 user 註冊到某台 gateway，並寫入 presence。
     * 真實系統：WS 握手成功後，gateway 在 Redis 寫入 user→自己 的對映。
     */
    public function connect(string $user, string $gateway): void
    {
        $reg = $this->read();
        $reg[$user] = [
            'gateway'   => $gateway,
            'online'    => true,
            'last_seen' => time(),
        ];
        $this->write($reg);
    }

    /**
     * 心跳：刷新 last_seen，維持 presence online。
     * 真實系統：client 每隔數十秒送 ping，gateway 更新 Redis TTL。
     */
    public function heartbeat(string $user): bool
    {
        $reg = $this->read();
        if (!isset($reg[$user])) {
            return false;
        }
        $reg[$user]['last_seen'] = time();
        $reg[$user]['online'] = true;
        $this->write($reg);
        return true;
    }

    /**
     * 下線：清掉對映（真實系統為 WS 連線關閉事件 / TTL 到期）。
     */
    public function disconnect(string $user): void
    {
        $reg = $this->read();
        if (isset($reg[$user])) {
            $reg[$user]['online'] = false;
            $reg[$user]['gateway'] = null;
            $this->write($reg);
        }
    }

    /** 查詢某 user 目前連在哪台 gateway；離線回 null（路由據此決定即時投遞 vs 離線佇列）。 */
    public function gatewayOf(string $user): ?string
    {
        return $this->isOnline($user) ? ($this->read()[$user]['gateway'] ?? null) : null;
    }

    /** presence：考慮 TTL 過期，超時即視為離線。 */
    public function isOnline(string $user): bool
    {
        $reg = $this->read();
        if (empty($reg[$user]['online'])) {
            return false;
        }
        $age = time() - (int) ($reg[$user]['last_seen'] ?? 0);
        return $age <= self::TTL_SECONDS;
    }

    /** 回傳所有 user 的 presence 狀態（給測試頁顯示）。 */
    public function all(): array
    {
        $reg = $this->read();
        $out = [];
        foreach ($reg as $user => $r) {
            $out[$user] = [
                'gateway' => $r['gateway'] ?? null,
                'online'  => $this->isOnline($user),
            ];
        }
        return $out;
    }

    private function read(): array
    {
        $json = (string) file_get_contents($this->file);
        return json_decode($json, true) ?: [];
    }

    private function write(array $reg): void
    {
        file_put_contents(
            $this->file,
            json_encode($reg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
