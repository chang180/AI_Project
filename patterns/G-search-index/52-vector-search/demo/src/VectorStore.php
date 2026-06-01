<?php
declare(strict_types=1);

/**
 * 向量儲存（模擬向量資料庫的「原始向量 + metadata」層）
 * --------------------------------------------------
 * 真實系統：向量與 metadata 存在向量資料庫（如 Milvus / pgvector / Faiss + 物件儲存），
 * 索引（IVF/HNSW）另外建並常駐記憶體。這裡用 JSON 檔當「DB」，
 * 每筆 = { id, vec:[小維度浮點], meta:{label,...} }。
 *
 * 僅用 json + 檔案鎖（flock），零外部依賴。
 */
final class VectorStore
{
    private string $dbFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dbFile = $dataDir . '/vectors.json';
        if (!file_exists($this->dbFile)) {
            file_put_contents($this->dbFile, '{"seq":0,"items":[]}');
        }
    }

    /**
     * 新增一筆向量。回傳指派到的 id。
     * @param float[] $vec
     * @param array<string,mixed> $meta
     */
    public function add(array $vec, array $meta): int
    {
        $fp = fopen($this->dbFile, 'c+');
        flock($fp, LOCK_EX);
        $raw = (string) stream_get_contents($fp);
        $db = json_decode($raw !== '' ? $raw : '{"seq":0,"items":[]}', true);

        $id = (int) $db['seq'] + 1;
        $db['seq'] = $id;
        $db['items'][] = [
            'id'   => $id,
            'vec'  => array_map(static fn($x) => (float) $x, $vec),
            'meta' => $meta,
        ];

        ftruncate($fp, 0);
        rewind($fp);
        $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        fwrite($fp, $json !== false ? $json : '{"seq":0,"items":[]}');
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $id;
    }

    /** 取出所有向量（含 id / vec / meta） */
    public function all(): array
    {
        $db = json_decode((string) file_get_contents($this->dbFile), true) ?: ['items' => []];
        return $db['items'] ?? [];
    }

    public function count(): int
    {
        return count($this->all());
    }

    /** 維度（以第一筆為準；無資料回 0） */
    public function dim(): int
    {
        $items = $this->all();
        return $items ? count($items[0]['vec']) : 0;
    }

    /** 清空（測試用） */
    public function reset(): void
    {
        file_put_contents($this->dbFile, '{"seq":0,"items":[]}', LOCK_EX);
    }
}
