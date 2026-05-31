<?php
declare(strict_types=1);

/**
 * HLS 打包器（HLS Packager）— 本題精髓：直播滑動視窗 manifest
 * --------------------------------------------------
 * 對應真實元件：把即時轉碼輸出切成小 segment，並維護直播用的 media playlist (.m3u8)。
 *
 * 直播 manifest 與 VOD 最大差別：
 *   1. 不含 #EXT-X-ENDLIST（代表「還沒結束，請繼續回來抓」）。
 *   2. 滑動視窗：只保留「最近 N 個」segment，舊的直接從清單滾掉。
 *   3. #EXT-X-MEDIA-SEQUENCE 標示「目前清單第一段是全域第幾段」，
 *      隨舊段滾掉而遞增 —— 播放器靠它判斷連續性、是否落後 live edge。
 *
 * 因此觀眾永遠只能看到 live edge 附近的數秒，無法倒帶到開播時（除非另存 VOD）。
 *
 * 這裡用 JSON 檔記錄視窗內的 segment 序號清單；append() 加新段、超過視窗就丟最舊一段。
 */
final class HlsPackager
{
    /** 滑動視窗大小：只保留最近 N 個 segment（真實 LL-HLS 常 3~6 段） */
    public const WINDOW = 3;
    /** 每段目標長度（秒） */
    private const TARGET_DURATION = 2;

    private string $playlistFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->playlistFile = $dataDir . '/playlist.json';
        if (!file_exists($this->playlistFile)) {
            $this->write(['window' => [], 'media_sequence' => 0]);
        }
    }

    /** 開播時清空視窗（新的一場直播從第 0 段重來） */
    public function reset(): void
    {
        $this->write(['window' => [], 'media_sequence' => 0]);
    }

    /**
     * 加入一個新 segment（全域序號 $seq）。
     * 滑動視窗核心：超過 WINDOW 就移除最舊一段，並把 media_sequence 往前推。
     */
    public function append(int $seq): void
    {
        $pl = $this->read();
        $window = $pl['window'];
        $window[] = $seq;

        // 滑動：舊段滾掉 → media_sequence 遞增（= 清單第一段的全域序號）
        while (count($window) > self::WINDOW) {
            array_shift($window);          // 丟最舊一段（過期 segment 不再服務）
            $pl['media_sequence'] = (int) $pl['media_sequence'] + 1;
        }
        $pl['window'] = $window;
        $this->write($pl);
    }

    /**
     * 產出直播 media playlist 文字（.m3u8）。
     * $live = true 時不加 ENDLIST（直播中）；收播後可帶 false 收尾。
     */
    public function renderManifest(bool $live = true): string
    {
        $pl = $this->read();
        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-TARGETDURATION:' . self::TARGET_DURATION,
            // 滑動視窗的關鍵標籤：目前第一段是全域第幾段
            '#EXT-X-MEDIA-SEQUENCE:' . (int) $pl['media_sequence'],
        ];
        foreach ($pl['window'] as $seq) {
            $lines[] = '#EXTINF:' . number_format((float) self::TARGET_DURATION, 3) . ',';
            $lines[] = 'seg_' . $seq . '.ts';
        }
        if (!$live) {
            $lines[] = '#EXT-X-ENDLIST';   // 收播：告訴播放器直播結束
        }
        // 直播中刻意「不」加 ENDLIST → 播放器會持續回來重抓 manifest
        return implode("\n", $lines) . "\n";
    }

    /** 主清單（master）：列出各碼率子清單（這裡示意兩檔 ABR） */
    public function renderMaster(string $base, string $streamId): string
    {
        return implode("\n", [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-STREAM-INF:BANDWIDTH=1400000,RESOLUTION=1280x720',
            "$base/api/streams/$streamId/720p/index.m3u8",
            '#EXT-X-STREAM-INF:BANDWIDTH=400000,RESOLUTION=640x360',
            "$base/api/streams/$streamId/360p/index.m3u8",
        ]) . "\n";
    }

    /** 給測試頁用：目前視窗內的 segment 序號與 media sequence */
    public function status(): array
    {
        $pl = $this->read();
        return [
            'window' => $pl['window'],
            'media_sequence' => (int) $pl['media_sequence'],
            'window_size' => self::WINDOW,
        ];
    }

    private function read(): array
    {
        $fp = fopen($this->playlistFile, 'c+');
        flock($fp, LOCK_SH);
        $json = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_decode($json, true) ?: ['window' => [], 'media_sequence' => 0];
    }

    private function write(array $pl): void
    {
        $fp = fopen($this->playlistFile, 'c+');
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        $json = json_encode($pl, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        fwrite($fp, $json !== false ? $json : '{}');
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
