<?php
declare(strict_types=1);

/**
 * DocStore — 文件儲存（模擬文件快照 + 版本號 + 操作日誌）
 * --------------------------------------------------
 * 對應真實元件：
 *   - 文件本體 / 快照（content）
 *   - 權威版本號（version，單調遞增）
 *   - append-only 操作日誌（history）—— OT 收斂的真相來源
 *
 * 真實系統：快照存物件儲存，版本號由協作伺服器權威產生，操作日誌持久化於
 * append-only 儲存（如 Bookkeeper / Kafka / WAL），並定期壓縮成快照。
 * 這裡用單一 JSON 檔當「文件 + 版本 + 日誌」的持久層，並用檔案鎖確保
 * 「提交操作」這個臨界區（讀版本→transform→套用→遞增版本→寫日誌）為原子。
 */
final class DocStore
{
    private string $file;

    public function __construct(string $dataDir, private string $docId = 'demo-doc')
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/' . $this->docId . '.json';
        if (!file_exists($this->file)) {
            $this->write([
                'doc_id'  => $this->docId,
                'content' => '',     // 文件目前內容（= 最新快照）
                'version' => 0,      // 權威版本號
                'history' => [],     // 操作日誌（已套用、已 transform 過的權威操作）
            ]);
        }
    }

    /** 讀取整份文件狀態（內容 + 版本 + 日誌）。 */
    public function load(): array
    {
        $json = (string) file_get_contents($this->file);
        return json_decode($json, true) ?: [
            'doc_id' => $this->docId, 'content' => '', 'version' => 0, 'history' => [],
        ];
    }

    public function content(): string { return (string) $this->load()['content']; }
    public function version(): int    { return (int) $this->load()['version']; }
    public function history(): array  { return (array) $this->load()['history']; }

    /**
     * 提交一個操作（OT 核心流程，整段以檔案鎖保護為原子臨界區）。
     *
     * 流程：
     *   1. 取得目前權威版本與 baseVersion 之後「中間已套用的操作」。
     *   2. 把進來的操作依序對每個中間操作做 transform（追上權威版本）。
     *   3. 套用 transform 後的操作 → 新內容。
     *   4. 權威版本 +1、把 transform 後的操作寫入 append-only 日誌。
     *
     * @param int   $baseVersion 客戶端送出時所基於的版本
     * @param array $op          OTEngine::insert()/delete() 產生的操作
     * @return array{version:int,content:string,applied:array} 套用結果
     */
    public function submit(int $baseVersion, array $op, string $author = 'anon'): array
    {
        $fp = fopen($this->file, 'c+');
        flock($fp, LOCK_EX);

        $raw   = stream_get_contents($fp);
        $state = json_decode($raw ?: '', true) ?: [
            'doc_id' => $this->docId, 'content' => '', 'version' => 0, 'history' => [],
        ];

        $authoritative = (int) $state['version'];
        $history       = (array) $state['history'];

        // baseVersion 之後、已套用的「中間操作」(concurrent ops)
        $base = max(0, min($baseVersion, $authoritative));
        $transformed = $op;
        for ($v = $base; $v < $authoritative; $v++) {
            if (isset($history[$v])) {
                // 對每個更早被序列化的操作做 transform，逐步追上權威版本
                $transformed = OTEngine::transform($transformed, $history[$v]['op']);
            }
        }

        // 套用 transform 後的操作 → 收斂的新內容
        $newContent = OTEngine::apply((string) $state['content'], $transformed);
        $newVersion = $authoritative + 1;

        $entry = [
            'version' => $newVersion,
            'author'  => $author,
            'op'      => $transformed,        // 日誌中存「已 transform 過的權威操作」
            'orig'    => $op,                 // 同時保留客戶端原始意圖（除錯用）
            'base'    => $baseVersion,
            'ts'      => gmdate('c'),
        ];
        $history[]      = $entry;
        $state['content'] = $newContent;
        $state['version'] = $newVersion;
        $state['history'] = $history;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $this->encode($state));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return ['version' => $newVersion, 'content' => $newContent, 'applied' => $transformed];
    }

    /** 重設文件（清空內容、版本歸零、清日誌）— 方便 demo 反覆測試。 */
    public function reset(): void
    {
        $this->write([
            'doc_id' => $this->docId, 'content' => '', 'version' => 0, 'history' => [],
        ]);
    }

    private function write(array $state): void
    {
        file_put_contents($this->file, $this->encode($state), LOCK_EX);
    }

    private function encode(array $state): string
    {
        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
