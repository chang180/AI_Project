<?php
declare(strict_types=1);

/**
 * 上傳服務（Upload Service）
 * --------------------------------------------------
 * 對應真實系統：大檔上傳採「分塊 + 斷點續傳 + 預簽章 URL 直傳物件儲存」。
 *   - createUpload()：發 upload_id 與「各分塊的預簽章直傳位址」概念。
 *                     真實系統客戶端會把每塊 bytes 直接 PUT 進物件儲存，
 *                     不經過 App 伺服器（App 不被大流量壓垮）。本 demo 為了
 *                     可在單機跑，提供自家分塊端點，但語意完全相同。
 *   - receivePart()：接收第 index 塊，標記已收。任何一塊失敗只需重傳該塊。
 *   - complete()：所有分塊到齊 → 合併成原片落物件儲存 → 建立影片中繼資料
 *                 → 觸發轉碼管線（回 202，非同步）。
 */
final class UploadService
{
    private const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB / 塊（示意）

    public function __construct(
        private MetadataStore $store,
        private TranscodePipeline $pipeline,
    ) {}

    /**
     * 建立上傳工作階段。
     * @return array{upload_id:string, video_id:string, chunk_size:int, total_parts:int, presigned_urls:list<string>}
     */
    public function createUpload(string $filename, int $size, string $title, string $baseUrl): array
    {
        $uploadId = 'up_' . bin2hex(random_bytes(5));
        $videoId  = 'vid_' . bin2hex(random_bytes(5));
        $totalParts = max(1, (int) ceil($size / self::CHUNK_SIZE));

        // 預簽章 URL 概念：每塊一個有時效的直傳位址（此處指向自家端點示意）
        $presigned = [];
        for ($i = 0; $i < $totalParts; $i++) {
            $presigned[] = "$baseUrl/api/v1/uploads/$uploadId/parts/$i";
        }

        $this->store->putUpload($uploadId, [
            'upload_id'    => $uploadId,
            'video_id'     => $videoId,
            'filename'     => $filename,
            'title'        => $title,
            'size'         => $size,
            'chunk_size'   => self::CHUNK_SIZE,
            'total_parts'  => $totalParts,
            'received'     => [],          // 已收到的 part index → bytes 數
            'status'       => 'UPLOADING',
            'origin_key'   => "$videoId/original.bin",
            'created_at'   => gmdate('c'),
        ]);

        return [
            'upload_id'      => $uploadId,
            'video_id'       => $videoId,
            'chunk_size'     => self::CHUNK_SIZE,
            'total_parts'    => $totalParts,
            'presigned_urls' => $presigned,
        ];
    }

    /**
     * 接收一個分塊。斷點續傳：同一塊可重傳，冪等覆蓋。
     * @return array{upload_id:string, index:int, received:int, parts_done:int, total_parts:int}
     */
    public function receivePart(string $uploadId, int $index, string $bytes): array
    {
        $up = $this->store->getUpload($uploadId);
        if ($up === null) {
            throw new RuntimeException('upload_id 不存在', 404);
        }
        if ($index < 0 || $index >= $up['total_parts']) {
            throw new RuntimeException('分塊 index 超出範圍', 400);
        }

        // 直接把分塊寫進「物件儲存」的分塊區（multipart part）
        $this->store->putObject("$uploadId/parts/$index", $bytes);

        $updated = $this->store->updateUpload($uploadId, function (array $u) use ($index, $bytes) {
            $u['received'][(string) $index] = strlen($bytes);
            return $u;
        });

        return [
            'upload_id'   => $uploadId,
            'index'       => $index,
            'received'    => strlen($bytes),
            'parts_done'  => count($updated['received']),
            'total_parts' => $updated['total_parts'],
        ];
    }

    /**
     * 完成上傳：合併分塊 → 原片落物件儲存 → 建立影片中繼資料 → 觸發轉碼（非同步）。
     * @return array{video_id:string, status:string, origin_key:string}
     */
    public function complete(string $uploadId): array
    {
        $up = $this->store->getUpload($uploadId);
        if ($up === null) {
            throw new RuntimeException('upload_id 不存在', 404);
        }
        if (count($up['received']) < $up['total_parts']) {
            throw new RuntimeException(
                '尚有分塊未上傳（' . count($up['received']) . '/' . $up['total_parts'] . '）',
                409
            );
        }

        // 依序合併分塊 → 原片（物件儲存 multipart complete 的概念）
        $originKey = $up['origin_key'];
        $this->store->putObject($originKey, ''); // 清空/建立
        for ($i = 0; $i < $up['total_parts']; $i++) {
            $part = $this->store->getObject("$uploadId/parts/$i") ?? '';
            $this->store->appendObject($originKey, $part);
        }

        $videoId = $up['video_id'];
        $this->store->putVideo($videoId, [
            'video_id'   => $videoId,
            'title'      => $up['title'],
            'filename'   => $up['filename'],
            'origin_key' => $originKey,
            'origin_size'=> $this->store->objectSize($originKey),
            'status'     => 'QUEUED',     // 整支影片的總狀態
            'renditions' => [],           // 由轉碼管線填入
            'views'      => 0,
            'created_at' => gmdate('c'),
        ]);

        $this->store->updateUpload($uploadId, function (array $u) {
            $u['status'] = 'COMPLETED';
            return $u;
        });

        // 觸發轉碼管線：fan-out 多解析度 job（QUEUED）
        $this->pipeline->enqueue($videoId);

        return ['video_id' => $videoId, 'status' => 'QUEUED', 'origin_key' => $originKey];
    }
}
