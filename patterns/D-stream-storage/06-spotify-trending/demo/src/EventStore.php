<?php
declare(strict_types=1);

/**
 * 串流算子狀態儲存（模擬 Flink 的有狀態算子）
 * --------------------------------------------------
 * 真實系統：聚合器的 sketch / Top-K heap 狀態存在串流框架的 state backend
 *           （RocksDB / 記憶體），並做 checkpoint。
 * 這裡用 JSON 檔保存單行程的狀態，讓 PHP 內建伺服器多次請求間能延續，
 * 以呈現「持續累積的串流計數」概念。
 *
 * 同時保留一份「精確計數」（exact map），純粹是為了在 demo 頁面上
 * 對比 sketch 估計值 vs 真值，讓你直接看到近似誤差（估計值 ≥ 真值）。
 */
final class EventStore
{
    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/state.json';
    }

    /** 讀出整體狀態（不存在則回預設空狀態） */
    public function load(): array
    {
        if (!file_exists($this->file)) {
            return $this->emptyState();
        }
        $json = (string) file_get_contents($this->file);
        $data = json_decode($json, true);
        return is_array($data) ? $data : $this->emptyState();
    }

    /** 寫回整體狀態（檔案鎖避免並行寫壞） */
    public function save(array $state): void
    {
        file_put_contents(
            $this->file,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /** 清空狀態（測試頁的「重置」用） */
    public function reset(): void
    {
        $this->save($this->emptyState());
    }

    /**
     * 預設空狀態：sketch 用刻意偏窄的 w=32、d=4（128 格，固定記憶體）。
     * demo 故意把寬度調小，好讓雜湊碰撞造成的「估計值偏大」誤差肉眼可見
     * （低播放量的歌最明顯）；真實系統會把 w 調到數十萬，誤差才會壓到可忽略。
     */
    private function emptyState(): array
    {
        return [
            'sketch' => (new CountMinSketch(32, 4))->toArray(),
            'candidates' => [],   // Top-K 候選集合
            'exact' => [],        // 精確計數（僅供對比誤差）
            'events' => 0,        // 已處理事件總數
            'decay_rounds' => 0,  // 已套用的衰減輪數
        ];
    }
}
