<?php
declare(strict_types=1);

/**
 * 同步服務（SyncService）— 串起分塊去重 + 增量同步 + 衝突偵測
 * --------------------------------------------------
 * 對應真實系統的同步服務：接收上傳、比對伺服器已有的 chunk（去重）、
 * 只接收新出現的 chunk（delta 增量），並在 commit 時以版本向量偵測衝突。
 */
final class SyncService
{
    public function __construct(
        private Chunker $chunker,
        private BlobStore $blobs,
        private FileIndex $index,
    ) {}

    /**
     * 上傳一個「檔案」的新內容。
     *
     * 流程：
     *  1) 客戶端切塊算 hash（Chunker）。
     *  2) chunks:check —— 比對 BlobStore 已有哪些，算出 missing（delta）。
     *  3) 只上傳 missing 的 chunk（去重 + 省頻寬）。
     *  4) commit —— 以 baseVersion / 版本向量偵測衝突；無衝突才產生新版本。
     *
     * @param int $baseVersion 客戶端認知的基底版本（用於衝突偵測；首次上傳傳 0）
     * @return array 結果摘要（給路由與測試頁顯示）
     */
    public function upload(string $fileId, string $content, string $device, int $baseVersion): array
    {
        // (1) 切塊
        $chunks = $this->chunker->split($content);
        $hashes = array_map(static fn(array $c): string => $c['hash'], $chunks);

        // (2) chunks:check —— 算出伺服器缺少的（delta）
        $missing = [];
        foreach ($chunks as $c) {
            if (!$this->blobs->has($c['hash'])) {
                $missing[$c['hash']] = $c['data'];
            }
        }

        // (4-先) 衝突偵測：commit 前先檢查基底版本是否落後
        $serverVersion = $this->index->latestVersion($fileId);
        $isConflict = ($serverVersion > 0 && $baseVersion < $serverVersion);

        if ($isConflict) {
            // 不靜默覆蓋：較晚者另存為衝突副本（conflicted copy）。
            // 仍要把內容存進去（去重後），但落在新的 fileId，兩份都保留。
            $conflictId = $fileId . ' (' . $device . ' 的衝突副本)';
            $newlyStored = $this->storeMissing($missing);
            $conflictVer = $this->index->commit(
                $conflictId,
                $hashes,
                $device,
                $this->bumpClock($this->index->vectorClock($fileId), $device)
            );
            return [
                'status' => 'conflict',
                'fileId' => $fileId,
                'server_version' => $serverVersion,
                'base_version' => $baseVersion,
                'conflict_copy' => $conflictId,
                'conflict_version' => $conflictVer,
                'total_chunks' => count($chunks),
                'missing_chunks' => count($missing),
                'reused_chunks' => count($chunks) - count($missing),
                'newly_stored' => $newlyStored,
                'chunk_hashes' => $hashes,
            ];
        }

        // (3) 只上傳 missing 的 chunk
        $newlyStored = $this->storeMissing($missing);

        // 對「沿用」的既有 chunk 也遞增引用計數（新版本引用了它們）
        foreach ($chunks as $c) {
            if (!isset($missing[$c['hash']])) {
                $this->blobs->put($c['hash'], $c['data']); // 已存在 → 去重命中 + ref_count++
            }
        }

        // (4) commit 新版本
        $newClock = $this->bumpClock($this->index->vectorClock($fileId), $device);
        $version = $this->index->commit($fileId, $hashes, $device, $newClock);

        $totalBytes = strlen($content);
        $deltaBytes = array_sum(array_map('strlen', $missing));
        return [
            'status' => 'ok',
            'fileId' => $fileId,
            'version' => $version,
            'total_chunks' => count($chunks),
            'missing_chunks' => count($missing),   // 真正上傳的（delta）
            'reused_chunks' => count($chunks) - count($missing), // 去重沿用的
            'newly_stored' => $newlyStored,
            'total_bytes' => $totalBytes,
            'delta_bytes' => $deltaBytes,          // 增量同步實際上傳量
            'saved_ratio' => $totalBytes > 0
                ? round(($totalBytes - $deltaBytes) / $totalBytes * 100, 1)
                : 0.0,
            'chunk_hashes' => $hashes,
            'blob_stats' => $this->blobs->stats(),
        ];
    }

    /** 把 missing 的 chunk 寫入 BlobStore，回傳實際新存的數量。 */
    private function storeMissing(array $missing): int
    {
        $n = 0;
        foreach ($missing as $hash => $data) {
            if ($this->blobs->put($hash, $data)) {
                $n++;
            }
        }
        return $n;
    }

    /** 版本向量遞增：該裝置的計數 +1，代表它產生了一次新提交。 */
    private function bumpClock(array $clock, string $device): array
    {
        $clock[$device] = ($clock[$device] ?? 0) + 1;
        return $clock;
    }
}
