<?php
declare(strict_types=1);

/**
 * 轉碼管線（Transcode Pipeline）
 * --------------------------------------------------
 * 對應真實系統：上傳完成 → 任務進「轉碼佇列」→ 無狀態 Worker（ffmpeg）消費，
 * 把原片轉成多解析度/多碼率並切片，產出 segment（.ts）與 HLS manifest（.m3u8），
 * 回寫中繼 DB 各 rendition 的狀態。
 *
 * 每支影片 fan-out 成多個 rendition job，各自跑狀態機：
 *     QUEUED → TRANSCODING → READY        （失敗則 FAILED，真實系統可重試/死信）
 *
 * 業界做法「低解析度先 READY」（漸進可用）：使用者可先看 240p，高解析度陸續補上。
 * 本 demo 不做真正編碼（誠實聲明見 README），step() 每次推進一個 job 一格狀態，
 * 並在轉到 READY 時把該解析度的 media manifest（.m3u8 文字）寫進物件儲存。
 */
final class TranscodePipeline
{
    /** 解析度梯度（低→高），低的先轉先 READY */
    private const RENDITIONS = [
        '240p'  => ['bitrate' => 400,  'res' => '426x240'],
        '480p'  => ['bitrate' => 1000, 'res' => '854x480'],
        '720p'  => ['bitrate' => 2500, 'res' => '1280x720'],
        '1080p' => ['bitrate' => 4500, 'res' => '1920x1080'],
    ];
    private const SEGMENT_SECONDS = 6; // VOD 常用 ~6 秒一段

    public function __construct(private MetadataStore $store) {}

    /** 上傳完成後 fan-out：為每個解析度建立 QUEUED job */
    public function enqueue(string $videoId): void
    {
        $this->store->updateVideo($videoId, function (array $v) {
            $renditions = [];
            foreach (self::RENDITIONS as $name => $meta) {
                $renditions[$name] = [
                    'resolution'   => $name,
                    'bitrate_kbps' => $meta['bitrate'],
                    'frame'        => $meta['res'],
                    'status'       => 'QUEUED',
                    'playlist_key' => null,
                ];
            }
            $v['renditions'] = $renditions;
            $v['status']     = 'QUEUED';
            return $v;
        });
    }

    /**
     * 推進一步：模擬 Worker 取出工作並前進一格狀態機。
     * 策略：低解析度優先（先把 240p 推到 READY，再 480p…），呈現「漸進可用」。
     * @return array{advanced: ?string, from: ?string, to: ?string, video_status: string}
     */
    public function step(string $videoId): array
    {
        $result = ['advanced' => null, 'from' => null, 'to' => null, 'video_status' => 'READY'];

        $video = $this->store->updateVideo($videoId, function (array $v) use (&$result) {
            $order = array_keys(self::RENDITIONS); // 低→高
            foreach ($order as $name) {
                $r = $v['renditions'][$name] ?? null;
                if ($r === null) {
                    continue;
                }
                if ($r['status'] === 'QUEUED') {
                    $v['renditions'][$name]['status'] = 'TRANSCODING';
                    $result = ['advanced' => $name, 'from' => 'QUEUED', 'to' => 'TRANSCODING', 'video_status' => $v['status']];
                    break;
                }
                if ($r['status'] === 'TRANSCODING') {
                    // 完成轉碼：產出該解析度的 media manifest 落物件儲存
                    $key = "{$v['video_id']}/{$name}/index.m3u8";
                    $v['renditions'][$name]['status'] = 'READY';
                    $v['renditions'][$name]['playlist_key'] = $key;
                    $result = ['advanced' => $name, 'from' => 'TRANSCODING', 'to' => 'READY', 'video_status' => $v['status']];
                    break;
                }
            }

            // 整支影片總狀態：任一非 READY → TRANSCODING；全 READY → READY
            $allReady = true;
            $anyWorking = false;
            foreach ($v['renditions'] as $r) {
                if ($r['status'] !== 'READY') {
                    $allReady = false;
                }
                if (in_array($r['status'], ['TRANSCODING', 'QUEUED'], true)) {
                    $anyWorking = true;
                }
            }
            $v['status'] = $allReady ? 'READY' : ($anyWorking ? 'TRANSCODING' : $v['status']);
            return $v;
        });

        if ($video === null) {
            throw new RuntimeException('video_id 不存在', 404);
        }

        // 若有 rendition 剛轉到 READY，把它的 media manifest 寫進物件儲存
        if ($result['to'] === 'READY' && $result['advanced'] !== null) {
            $name = $result['advanced'];
            $this->store->putObject(
                "{$videoId}/{$name}/index.m3u8",
                $this->buildMediaManifest($name)
            );
        }

        $result['video_status'] = $video['status'];
        return $result;
    }

    /** 把整支影片一路推到全 READY（測試/示範用：連續呼叫 step 直到收斂） */
    public function runToReady(string $videoId): array
    {
        $log = [];
        for ($i = 0; $i < 100; $i++) {
            $r = $this->step($videoId);
            if ($r['advanced'] === null && $r['video_status'] === 'READY') {
                break;
            }
            $log[] = $r;
            if ($r['video_status'] === 'READY' && $r['advanced'] === null) {
                break;
            }
        }
        return $log;
    }

    /**
     * 產生 master manifest（HLS）：列出所有「已 READY」的解析度子清單與其頻寬，
     * 客戶端據此自適應選流（ABR）。
     */
    public function buildMasterManifest(string $videoId): string
    {
        $video = $this->store->getVideo($videoId);
        if ($video === null) {
            throw new RuntimeException('video_id 不存在', 404);
        }

        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];
        foreach (self::RENDITIONS as $name => $meta) {
            $r = $video['renditions'][$name] ?? null;
            if ($r === null || $r['status'] !== 'READY') {
                continue; // 只列已就緒的解析度（漸進可用）
            }
            $bw = $meta['bitrate'] * 1000;
            $lines[] = "#EXT-X-STREAM-INF:BANDWIDTH={$bw},RESOLUTION={$meta['res']}";
            $lines[] = "{$name}/index.m3u8";
        }
        if (count($lines) === 2) {
            $lines[] = '# (尚無任何解析度就緒，請先推進轉碼)';
        }
        return implode("\n", $lines) . "\n";
    }

    /** 產生某解析度的 media manifest（列出該解析度的 segment .ts） */
    public function buildMediaManifest(string $rendition, int $durationSeconds = 30): string
    {
        $count = max(1, (int) ceil($durationSeconds / self::SEGMENT_SECONDS));
        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-TARGETDURATION:' . self::SEGMENT_SECONDS,
            '#EXT-X-MEDIA-SEQUENCE:0',
        ];
        for ($i = 0; $i < $count; $i++) {
            $lines[] = '#EXTINF:' . number_format(self::SEGMENT_SECONDS, 1) . ',';
            $lines[] = sprintf('seg_%03d.ts', $i);
        }
        $lines[] = '#EXT-X-ENDLIST';
        return implode("\n", $lines) . "\n";
    }
}
