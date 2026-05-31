<?php
declare(strict_types=1);

/**
 * 內容定址 Blob 儲存（BlobStore）
 * --------------------------------------------------
 * 真實系統：blob 本體放物件儲存（S3 / GCS），key = chunk 的內容 hash，
 * 多副本 / EC 編碼保證極高耐久；相同內容只落地一份（全系統去重）。
 *
 * 本 Demo 用 JSON 檔模擬物件儲存：以 hash 為 key，相同 chunk 只存一份，
 * 並維護引用計數（ref_count）與去重統計（省下的位元組）。
 */
final class BlobStore
{
    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/blobs.json';
        if (!file_exists($this->file)) {
            // blobs: hash => {size, ref_count}；stats: 累計去重統計
            $this->write([
                'blobs' => [],
                'stats' => ['stored_bytes' => 0, 'dedup_hits' => 0, 'saved_bytes' => 0],
            ]);
        }
    }

    /** 此 hash 是否已存在（= 上傳前 chunks:check 的核心判斷）。 */
    public function has(string $hash): bool
    {
        return isset($this->read()['blobs'][$hash]);
    }

    /**
     * 寫入一個 chunk（內容定址）。
     * 已存在 → 不重複存（去重），僅遞增 ref_count 並累計節省；
     * 不存在 → 落地一份並累計實際儲存量。
     *
     * @return bool true=實際新存（missing），false=去重命中（已存在）
     */
    public function put(string $hash, string $data): bool
    {
        $db = $this->read();
        if (isset($db['blobs'][$hash])) {
            $db['blobs'][$hash]['ref_count']++;
            $db['stats']['dedup_hits']++;
            $db['stats']['saved_bytes'] += strlen($data); // 這份內容因去重而省下
            $this->write($db);
            return false;
        }
        $db['blobs'][$hash] = ['size' => strlen($data), 'ref_count' => 1];
        $db['stats']['stored_bytes'] += strlen($data);
        $this->write($db);
        return true;
    }

    /** 釋放引用；ref_count 歸零才可垃圾回收（避免誤刪共享 chunk）。 */
    public function release(string $hash): void
    {
        $db = $this->read();
        if (!isset($db['blobs'][$hash])) {
            return;
        }
        $db['blobs'][$hash]['ref_count']--;
        if ($db['blobs'][$hash]['ref_count'] <= 0) {
            unset($db['blobs'][$hash]); // GC：真實系統常加寬限期延遲回收
        }
        $this->write($db);
    }

    /** @return array{stored_bytes:int, dedup_hits:int, saved_bytes:int, unique_chunks:int} */
    public function stats(): array
    {
        $db = $this->read();
        $s = $db['stats'];
        $s['unique_chunks'] = count($db['blobs']);
        return $s;
    }

    private function read(): array
    {
        return json_decode((string) file_get_contents($this->file), true) ?: ['blobs' => [], 'stats' => []];
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
