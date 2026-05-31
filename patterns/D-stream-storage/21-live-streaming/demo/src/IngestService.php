<?php
declare(strict_types=1);

/**
 * 攝入服務（Ingest Service）
 * --------------------------------------------------
 * 對應真實元件：接收主播 RTMP / SRT / WebRTC 推流的攝入伺服器。
 *
 * 真實系統：主播用 OBS 把畫面推到 ingest，平台即時轉碼後由 packager 切成 segment。
 * 這裡用 JSON 檔當「直播狀態」，用檔案鎖維護一個「全域遞增的 segment sequence」，
 * 每次「產生 segment」就模擬主播推了 2 秒畫面、被即時轉碼並切出一個新的切片。
 *
 * 注意：直播是 append-only 的即時流 —— 我們不保存所有 segment，
 *       只記錄「目前產到第幾段」，實際留存交給 HlsPackager 的滑動視窗。
 */
final class IngestService
{
    private string $stateFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->stateFile = $dataDir . '/stream.json';
        if (!file_exists($this->stateFile)) {
            $this->write([
                'status' => 'IDLE',     // IDLE / LIVE / ENDED
                'title' => '',
                'started_at' => null,
                'next_seq' => 0,        // 下一個 segment 的全域序號（單調遞增）
            ]);
        }
    }

    /** 開始直播：重置狀態並進入 LIVE */
    public function start(string $title): array
    {
        $state = [
            'status' => 'LIVE',
            'title' => $title !== '' ? $title : '未命名直播',
            'started_at' => gmdate('c'),
            'next_seq' => 0,
        ];
        $this->write($state);
        return $state;
    }

    /**
     * 攝入推進一段：模擬主播推了 2 秒、即時轉碼切出一個新 segment。
     * 回傳新 segment 的全域序號（單調遞增，永不回頭）。
     * 真實系統：這是 packager 從即時轉碼輸出切出的下一段 .ts。
     */
    public function produceSegment(): int
    {
        $state = $this->read();
        if ($state['status'] !== 'LIVE') {
            throw new RuntimeException('直播未開始，無法產生 segment');
        }
        $seq = (int) $state['next_seq'];
        $state['next_seq'] = $seq + 1;
        $this->write($state);
        return $seq; // 本段的全域序號
    }

    /** 結束直播 */
    public function stop(): array
    {
        $state = $this->read();
        $state['status'] = 'ENDED';
        $this->write($state);
        return $state;
    }

    public function state(): array
    {
        return $this->read();
    }

    public function isLive(): bool
    {
        return $this->read()['status'] === 'LIVE';
    }

    // ---- 檔案層（檔案鎖保證 sequence 不會競態重複） ----
    private function read(): array
    {
        $fp = fopen($this->stateFile, 'c+');
        flock($fp, LOCK_SH);
        $json = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_decode($json, true) ?: [
            'status' => 'IDLE', 'title' => '', 'started_at' => null, 'next_seq' => 0,
        ];
    }

    private function write(array $state): void
    {
        $fp = fopen($this->stateFile, 'c+');
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        fwrite($fp, $json !== false ? $json : '{}');
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
