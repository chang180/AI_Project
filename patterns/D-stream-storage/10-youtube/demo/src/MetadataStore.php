<?php
declare(strict_types=1);

/**
 * 中繼資料儲存（模擬 Metadata DB）
 * --------------------------------------------------
 * 真實系統：影片本體（原片 + 轉碼後分段）放物件儲存（S3 類），
 * DB 只存「中繼資料 + 物件 key + 各解析度狀態 + 觀看數」，一筆約 KB 級。
 * 這裡用 JSON 檔當「DB」，檔案鎖保證並發一致；觀看數真實系統會走
 * Kafka 非同步聚合，這裡直接累加示意（同 ① 短碼題的計數解耦思路）。
 *
 * 同時模擬「物件儲存」：上傳合併後的原片、轉碼產出的 segment / manifest
 * 皆以檔案落在 data/objects/ 之下，DB 只保存其 key。
 */
final class MetadataStore
{
    private string $videosFile;   // 影片中繼資料（含 renditions / views）
    private string $uploadsFile;  // 進行中的上傳工作階段
    private string $objectsDir;   // 模擬物件儲存

    public function __construct(private string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->objectsDir = $dataDir . '/objects';
        if (!is_dir($this->objectsDir)) {
            mkdir($this->objectsDir, 0777, true);
        }
        $this->videosFile  = $dataDir . '/videos.json';
        $this->uploadsFile = $dataDir . '/uploads.json';
        foreach ([$this->videosFile, $this->uploadsFile] as $f) {
            if (!file_exists($f)) {
                file_put_contents($f, '{}');
            }
        }
    }

    // ---------- 影片中繼資料 ----------

    public function putVideo(string $videoId, array $record): void
    {
        $db = $this->read($this->videosFile);
        $db[$videoId] = $record;
        $this->write($this->videosFile, $db);
    }

    public function getVideo(string $videoId): ?array
    {
        $db = $this->read($this->videosFile);
        return $db[$videoId] ?? null;
    }

    /** 以回呼原子更新一筆影片（讀-改-寫包在檔案鎖內，避免並發覆寫） */
    public function updateVideo(string $videoId, callable $fn): ?array
    {
        $fp = fopen($this->videosFile, 'c+');
        flock($fp, LOCK_EX);
        $db = json_decode((string) stream_get_contents($fp), true) ?: [];
        if (!isset($db[$videoId])) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return null;
        }
        $db[$videoId] = $fn($db[$videoId]);
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $db[$videoId];
    }

    /** @return array<string,array> 所有影片，新→舊 */
    public function allVideos(): array
    {
        $db = $this->read($this->videosFile);
        uasort($db, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return $db;
    }

    // ---------- 上傳工作階段 ----------

    public function putUpload(string $uploadId, array $record): void
    {
        $db = $this->read($this->uploadsFile);
        $db[$uploadId] = $record;
        $this->write($this->uploadsFile, $db);
    }

    public function getUpload(string $uploadId): ?array
    {
        $db = $this->read($this->uploadsFile);
        return $db[$uploadId] ?? null;
    }

    public function updateUpload(string $uploadId, callable $fn): ?array
    {
        $fp = fopen($this->uploadsFile, 'c+');
        flock($fp, LOCK_EX);
        $db = json_decode((string) stream_get_contents($fp), true) ?: [];
        if (!isset($db[$uploadId])) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return null;
        }
        $db[$uploadId] = $fn($db[$uploadId]);
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $db[$uploadId];
    }

    // ---------- 模擬物件儲存 ----------

    /** 寫入一個物件（回傳其 key）。真實系統即 S3 PutObject。 */
    public function putObject(string $key, string $bytes): string
    {
        $path = $this->objectsDir . '/' . $this->safe($key);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $bytes, LOCK_EX);
        return $key;
    }

    public function appendObject(string $key, string $bytes): void
    {
        $path = $this->objectsDir . '/' . $this->safe($key);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $bytes, FILE_APPEND | LOCK_EX);
    }

    public function getObject(string $key): ?string
    {
        $path = $this->objectsDir . '/' . $this->safe($key);
        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    public function objectSize(string $key): int
    {
        $path = $this->objectsDir . '/' . $this->safe($key);
        return is_file($path) ? (int) filesize($path) : 0;
    }

    /** 避免路徑跳脫，key 僅保留安全字元與斜線 */
    private function safe(string $key): string
    {
        $key = str_replace('..', '', $key);
        return preg_replace('#[^A-Za-z0-9_./-]#', '_', $key) ?? $key;
    }

    private function read(string $file): array
    {
        return json_decode((string) file_get_contents($file), true) ?: [];
    }

    private function write(string $file, array $db): void
    {
        file_put_contents(
            $file,
            json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
