<?php
declare(strict_types=1);

/**
 * 端點儲存（模擬 endpoints 表）
 * --------------------------------------------------
 * 真實系統：端點對映存於分片式 DB，secret 加密儲存（KMS / envelope encryption）。
 * 這裡用 JSON 檔當「DB」，呈現「註冊端點 → 取得 secret → 投遞時據此簽章」的核心流程。
 *
 * 注意：每個端點帶一個 mode 欄位（success / fail / slow），
 * 用來「模擬投遞結果」——這樣 demo 不必真的對外發 HTTP，
 * 也就不會在 PHP 單執行緒內建伺服器上自鎖（見 Dispatcher 註解）。
 */
final class EndpointStore
{
    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/endpoints.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, '{}');
        }
    }

    /**
     * 註冊端點，回傳含 secret 的記錄。
     * @param string $url   接收 webhook 的 callback URL
     * @param array<string> $events 訂閱的事件類型
     * @param string $mode  模擬投遞結果：success / fail / slow
     */
    public function register(string $url, array $events, string $mode = 'success'): array
    {
        $db = $this->read();
        $id = 'ep_' . substr(bin2hex(random_bytes(6)), 0, 8);
        $record = [
            'endpoint_id' => $id,
            'url'         => $url,
            // HMAC 共享密鑰：只在註冊時產生一次，雙方據此簽章/驗章
            'secret'      => 'whsec_' . bin2hex(random_bytes(16)),
            'events'      => $events,
            'mode'        => $mode,         // 模擬投遞結果用
            'state'       => 'ACTIVE',
            'created_at'  => gmdate('c'),
        ];
        $db[$id] = $record;
        $this->write($db);
        return $record;
    }

    public function get(string $id): ?array
    {
        $db = $this->read();
        return $db[$id] ?? null;
    }

    /** 找出訂閱了某事件類型的所有 ACTIVE 端點（fan-out 用） */
    public function subscribersOf(string $eventType): array
    {
        $out = [];
        foreach ($this->read() as $ep) {
            if ($ep['state'] === 'ACTIVE' && in_array($eventType, $ep['events'], true)) {
                $out[] = $ep;
            }
        }
        return $out;
    }

    /** 連續失敗過多 → 自動停用端點（真實系統的保護機制） */
    public function disable(string $id): void
    {
        $db = $this->read();
        if (isset($db[$id])) {
            $db[$id]['state'] = 'DISABLED';
            $this->write($db);
        }
    }

    /** @return array<string,array> */
    public function all(): array
    {
        return $this->read();
    }

    private function read(): array
    {
        $json = (string) file_get_contents($this->file);
        return json_decode($json, true) ?: [];
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
