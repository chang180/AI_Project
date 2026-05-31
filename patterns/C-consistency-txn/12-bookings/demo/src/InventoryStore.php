<?php
declare(strict_types=1);

/**
 * 庫存日曆儲存（模擬 room_inventory 表 + DB 交易/行鎖）
 * --------------------------------------------------
 * 真實系統：每房每日一列 room_inventory(room_id, date)，狀態 OPEN/HELD/BOOKED，
 *           並發控制用 DB 交易 + SELECT ... FOR UPDATE（悲觀）或
 *           UPDATE ... WHERE status='OPEN'（樂觀 / 條件更新）。
 *
 * 這裡用 JSON 檔當「庫存日曆表」，用 flock(LOCK_EX) 模擬「DB 交易 + 行鎖」：
 *   - transaction(callable)：取得排他檔案鎖 → 讀整份庫存 → 回呼在鎖內操作 →
 *     寫回 → 釋放鎖。鎖內為「臨界區」，等同 BEGIN ... COMMIT。
 *   - 這保證「區間可用性檢查 + 原子扣減」是不可分割的，兩個並發請求不會同時通過檢查。
 *
 * 資料結構（JSON）：
 *   { "rooms": { "R1": { "name": "...", "calendar": { "2026-06-01": {"status":"OPEN","hold_id":null,"version":0}, ... } } },
 *     "holds": { "H1": { "room_id":"R1", "dates":[...], "status":"HELD", "expires_at": 169... } } }
 */
final class InventoryStore
{
    private string $dbFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dbFile = $dataDir . '/inventory.json';
        if (!file_exists($this->dbFile)) {
            file_put_contents($this->dbFile, json_encode(['rooms' => [], 'holds' => []]));
        }
    }

    /**
     * 模擬一次 DB 交易：取得排他行鎖（flock）→ 在鎖內讀寫 → 提交。
     * $fn 收到「整份資料的參考」，回傳值即為交易結果回傳給呼叫端。
     * 回呼若拋出例外 → 視為 ROLLBACK（不寫回）。
     *
     * @param callable(array): array{0:mixed,1:bool} $fn
     *        回傳 [結果, 是否要寫回(commit)]
     * @return mixed 交易結果
     */
    public function transaction(callable $fn): mixed
    {
        $fp = fopen($this->dbFile, 'c+');
        if ($fp === false) {
            throw new RuntimeException('無法開啟庫存檔');
        }
        try {
            flock($fp, LOCK_EX);                 // === BEGIN：取得排他鎖（其他交易在此等待）===
            $raw = stream_get_contents($fp);
            $data = $raw !== '' ? json_decode($raw, true) : ['rooms' => [], 'holds' => []];

            [$result, $commit] = $fn($data);     // 臨界區：呼叫端在此檢查 + 扣減

            if ($commit) {                        // === COMMIT：寫回 ===
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                fflush($fp);
            }
            return $result;
        } finally {
            flock($fp, LOCK_UN);                  // === 釋放鎖 ===
            fclose($fp);
        }
    }

    /** 唯讀快照（搜尋/房況展示走這裡，真實系統對應讀副本，可接受短暫過期） */
    public function snapshot(): array
    {
        $raw = (string) file_get_contents($this->dbFile);
        return $raw !== '' ? (json_decode($raw, true) ?: ['rooms' => [], 'holds' => []]) : ['rooms' => [], 'holds' => []];
    }
}
