<?php
declare(strict_types=1);

/**
 * 分塊器（Chunker）
 * --------------------------------------------------
 * 真實系統（Dropbox）：用「內容定義分塊（CDC, Rabin 滾動雜湊）」依內容特徵切點，
 * 在檔案開頭插入幾個位元組時，後面的 chunk 邊界不會整體位移，去重 / delta 效果最佳。
 *
 * 本 Demo 為零依賴展示，採「固定大小分塊」：每 N 個位元組切一塊，逐塊算 sha256。
 * 以位元組（byte）切塊——本機 PHP 未載入 mbstring，且真實分塊面對的是二進位內容，
 * 本就不該以字元為單位，因此這與真實系統的處理方式一致。
 */
final class Chunker
{
    /** 預設 chunk 大小（位元組）。真實系統多為 4 MB；Demo 用小值方便觀察切塊。 */
    public function __construct(private int $chunkSize = 16) {}

    /**
     * 把內容切成固定大小 chunk，回傳每塊的 hash 與內容。
     *
     * @return list<array{hash:string, size:int, data:string}> 有序的 chunk 清單
     */
    public function split(string $content): array
    {
        $chunks = [];
        $len = strlen($content);                 // 以位元組計長度
        for ($offset = 0; $offset < $len; $offset += $this->chunkSize) {
            $data = substr($content, $offset, $this->chunkSize); // 以位元組切
            $chunks[] = [
                'hash' => $this->hash($data),    // 內容定址：sha256(內容)
                'size' => strlen($data),
                'data' => $data,
            ];
        }
        return $chunks;
    }

    /** 內容定址用的 hash：相同內容必得相同 hash → 天然去重的基礎。 */
    public function hash(string $data): string
    {
        // 截短只為 Demo 顯示好看；真實系統用完整 sha256（64 hex 字元）。
        return 'sha256:' . substr(hash('sha256', $data), 0, 12);
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }
}
