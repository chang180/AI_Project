<?php
declare(strict_types=1);

/**
 * 地點資料儲存（模擬 Place DB + 讀取快取）
 * --------------------------------------------------
 * 對應真實元件：地點主庫（PostgreSQL / DynamoDB），前面擋 Redis 快取。
 * 商家地點是「讀多寫少」的靜態資料（開店後座標幾乎不變），
 * 因此讀路徑可大量快取、用讀副本擴展；寫路徑相對稀疏。
 *
 * 這裡用 JSON 檔當「DB」，process-local 陣列當「快取」，並統計命中/未命中，
 * 以呈現讀多寫少場景的快取化核心概念。
 *
 * 地點欄位：id / name / category / rating / lat / lng
 */
final class PlaceStore
{
    private string $dbFile;
    private string $counterFile;
    /** @var array<string,array> process-local 快取（模擬 Redis） */
    private array $cache = [];
    private int $cacheHit = 0;
    private int $cacheMiss = 0;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dbFile      = $dataDir . '/places.json';
        $this->counterFile = $dataDir . '/counter.txt';
        if (!file_exists($this->dbFile)) {
            file_put_contents($this->dbFile, '{}');
        }
        if (!file_exists($this->counterFile)) {
            file_put_contents($this->counterFile, '1000'); // 起始 ID
        }
    }

    /** 原子遞增地點 ID（用檔案鎖模擬 DB sequence） */
    public function nextId(): int
    {
        $fp = fopen($this->counterFile, 'c+');
        flock($fp, LOCK_EX);
        $id = (int) trim((string) fread($fp, 64));
        $id++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $id);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $id;
    }

    /**
     * 新增地點（寫路徑，稀疏）。回傳完整地點記錄（含產生的 id）。
     * @return array{id:string, name:string, category:string, rating:float, lat:float, lng:float}
     */
    public function add(string $name, string $category, float $rating, float $lat, float $lng): array
    {
        $id  = 'p' . $this->nextId();
        $rec = [
            'id'       => $id,
            'name'     => $name,
            'category' => $category,
            'rating'   => $rating,
            'lat'      => $lat,
            'lng'      => $lng,
        ];
        $db       = $this->readDb();
        $db[$id]  = $rec;
        $this->writeDb($db);
        $this->cache[$id] = $rec; // 寫穿快取
        return $rec;
    }

    /** 讀路徑：先查快取，未命中才查「DB」並回填 */
    public function get(string $id): ?array
    {
        if (isset($this->cache[$id])) {
            $this->cacheHit++;
            return $this->cache[$id];
        }
        $this->cacheMiss++;
        $db = $this->readDb();
        if (!isset($db[$id])) {
            return null;
        }
        $this->cache[$id] = $db[$id];
        return $db[$id];
    }

    /** 全部地點（建立索引、測試頁列表用） */
    public function all(): array
    {
        return $this->readDb();
    }

    public function count(): int
    {
        return count($this->readDb());
    }

    public function cacheStats(): array
    {
        return ['hit' => $this->cacheHit, 'miss' => $this->cacheMiss];
    }

    private function readDb(): array
    {
        $json = (string) file_get_contents($this->dbFile);
        return json_decode($json, true) ?: [];
    }

    private function writeDb(array $db): void
    {
        file_put_contents(
            $this->dbFile,
            json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
