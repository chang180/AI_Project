<?php
declare(strict_types=1);

/**
 * 對映儲存（模擬 Metadata DB + 行內快取）
 * --------------------------------------------------
 * 真實系統：對映存分片式 DB，前面擋一層 Redis 快取（命中率 >95%）。
 * 這裡用 JSON 檔當「DB」，用 process-local 陣列當「快取」，並統計命中/未命中，
 * 以呈現讀路徑快取化的核心概念。
 */
final class Store
{
    private string $dbFile;
    private string $counterFile;
    /** @var array<string,array> process-local 快取 */
    private array $cache = [];
    private int $cacheHit = 0;
    private int $cacheMiss = 0;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dbFile = $dataDir . '/qrcodes.json';
        $this->counterFile = $dataDir . '/counter.txt';
        if (!file_exists($this->dbFile)) {
            file_put_contents($this->dbFile, '{}');
        }
        if (!file_exists($this->counterFile)) {
            file_put_contents($this->counterFile, '1000'); // 起始 ID
        }
    }

    /** KGS：原子遞增全域 ID（用檔案鎖模擬 DB sequence） */
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

    public function put(string $code, array $record): void
    {
        $db = $this->readDb();
        $db[$code] = $record;
        $this->writeDb($db);
        $this->cache[$code] = $record;      // 寫穿快取
    }

    /** 讀路徑：先查快取，未命中才查「DB」 */
    public function get(string $code): ?array
    {
        if (isset($this->cache[$code])) {
            $this->cacheHit++;
            return $this->cache[$code];
        }
        $this->cacheMiss++;
        $db = $this->readDb();
        if (!isset($db[$code])) {
            return null;
        }
        $this->cache[$code] = $db[$code];   // 回填快取
        return $db[$code];
    }

    /** 更新目標：動態 QR 的賣點，並主動失效快取（讀自己的寫） */
    public function updateTarget(string $code, string $url): bool
    {
        $db = $this->readDb();
        if (!isset($db[$code])) {
            return false;
        }
        $db[$code]['target_url'] = $url;
        $db[$code]['updated_at'] = gmdate('c');
        $this->writeDb($db);
        unset($this->cache[$code]);         // 失效，下次讀回填新值
        return true;
    }

    /** 掃描計數（真實系統走 Kafka 非同步聚合，這裡直接累加示意） */
    public function recordScan(string $code): void
    {
        $db = $this->readDb();
        if (isset($db[$code])) {
            $db[$code]['scans'] = ($db[$code]['scans'] ?? 0) + 1;
            $this->writeDb($db);
            unset($this->cache[$code]);
        }
    }

    public function all(): array
    {
        return $this->readDb();
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
        file_put_contents($this->dbFile, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
