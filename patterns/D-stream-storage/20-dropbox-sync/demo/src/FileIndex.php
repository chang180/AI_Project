<?php
declare(strict_types=1);

/**
 * 檔案索引（FileIndex）— 模擬 metadata DB 的 files / file_versions
 * --------------------------------------------------
 * 真實系統：關聯式、可分片，存「指標」不存內容——
 * 一個檔案是一連串 chunk hash 的有序清單；每次 commit 產生一個不可變的新版本，
 * 並記錄提交裝置與版本向量，支援版本歷史、還原與衝突偵測。
 *
 * 本 Demo 用 JSON 檔保存：fileId => { latest_version, versions[ver => {chunks, device, vector_clock} ] }。
 */
final class FileIndex
{
    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/index.json';
        if (!file_exists($this->file)) {
            $this->write([]);
        }
    }

    /** 取得檔案目前最新版本號（不存在則為 0，代表尚無任何版本）。 */
    public function latestVersion(string $fileId): int
    {
        $db = $this->read();
        return (int) ($db[$fileId]['latest_version'] ?? 0);
    }

    /** 取得某版本的有序 chunk hash 清單；查無回 null。 */
    public function chunksOf(string $fileId, ?int $version = null): ?array
    {
        $db = $this->read();
        if (!isset($db[$fileId])) {
            return null;
        }
        $version ??= (int) $db[$fileId]['latest_version'];
        return $db[$fileId]['versions'][(string) $version]['chunks'] ?? null;
    }

    /**
     * 提交新版本（append-only，不可變）。
     *
     * @param list<string> $chunkHashes 有序 chunk hash 清單
     * @param array<string,int> $vectorClock 版本向量（device_id => 計數）
     * @return int 新版本號
     */
    public function commit(string $fileId, array $chunkHashes, string $device, array $vectorClock): int
    {
        $db = $this->read();
        $newVer = $this->latestVersion($fileId) + 1;
        if (!isset($db[$fileId])) {
            $db[$fileId] = ['latest_version' => 0, 'versions' => []];
        }
        $db[$fileId]['versions'][(string) $newVer] = [
            'chunks' => array_values($chunkHashes),
            'device' => $device,
            'vector_clock' => $vectorClock,
            'created_at' => gmdate('c'),
        ];
        $db[$fileId]['latest_version'] = $newVer;
        $this->write($db);
        return $newVer;
    }

    /** 取得某版本的版本向量（用於衝突偵測）。 */
    public function vectorClock(string $fileId, ?int $version = null): array
    {
        $db = $this->read();
        if (!isset($db[$fileId])) {
            return [];
        }
        $version ??= (int) $db[$fileId]['latest_version'];
        return $db[$fileId]['versions'][(string) $version]['vector_clock'] ?? [];
    }

    /** 回傳所有檔案目前狀態，供測試頁列出。 */
    public function all(): array
    {
        return $this->read();
    }

    private function read(): array
    {
        return json_decode((string) file_get_contents($this->file), true) ?: [];
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
