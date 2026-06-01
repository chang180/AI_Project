<?php
declare(strict_types=1);

require_once __DIR__ . '/SlotMap.php';
require_once __DIR__ . '/CacheNode.php';

/**
 * Cluster — 把 SlotMap（slot 路由）+ 多個 CacheNode（shard 主從）組起來，
 *           並示範三大快取問題（穿透 / 擊穿 / 雪崩）與緩解手法。
 * ----------------------------------------------------------------------
 * 路由邏輯（真實）：
 *   slot = crc32(key) % 16384 → SlotMap 查 slot 屬於哪個 shard → 該 shard 的 CacheNode 處理。
 *
 * 三大問題（皆可實際觸發 + 開關緩解）：
 *   1) 穿透 penetration：查「根本不存在」的 key，快取永遠 miss，每次都打 DB。
 *        緩解：空值快取（cache null）+ 布隆過濾器（bloom）先擋掉一定不存在的 key。
 *   2) 擊穿 breakdown：單一「熱 key」過期瞬間，大量請求同時回源打 DB。
 *        緩解：singleflight / 互斥鎖，同一 key 同時只放一個請求回源，其餘等結果。
 *   3) 雪崩 avalanche：大量 key「同一時間」過期，回源洪峰打垮 DB。
 *        緩解：TTL 加隨機抖動（jitter），讓過期時間打散。
 *
 * DB 回源以一個慢速計數器模擬（記錄「打了 DB 幾次」），用來量化緩解效果。
 */
final class Cluster
{
    private string $dataDir;
    private SlotMap $slotMap;
    /** @var array<string,CacheNode> shard 名稱 → CacheNode */
    private array $nodes = [];
    private int $maxKeys;
    private string $policy;

    public function __construct(string $dataDir, int $maxKeys = 100, string $policy = 'lru')
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dataDir = rtrim($dataDir, '/\\');
        $this->maxKeys = $maxKeys;
        $this->policy = $policy;
        $this->slotMap = new SlotMap($this->dataDir);
        $this->loadPolicy();
    }

    // ============ 設定（maxmemory + 淘汰策略，存檔以跨請求保留） ============

    private function policyFile(): string
    {
        return $this->dataDir . '/policy.json';
    }

    private function loadPolicy(): void
    {
        $f = $this->policyFile();
        if (file_exists($f)) {
            $p = json_decode((string) file_get_contents($f), true);
            if (is_array($p)) {
                $this->policy = (string) ($p['policy'] ?? $this->policy);
                $this->maxKeys = (int) ($p['maxKeys'] ?? $this->maxKeys);
            }
        }
    }

    public function setPolicy(string $policy, int $maxKeys): void
    {
        $this->policy = in_array($policy, ['lru', 'lfu', 'noeviction'], true) ? $policy : 'lru';
        $this->maxKeys = max(1, $maxKeys);
        file_put_contents(
            $this->policyFile(),
            json_encode(['policy' => $this->policy, 'maxKeys' => $this->maxKeys], JSON_PRETTY_PRINT),
            LOCK_EX
        );
        $this->nodes = []; // 讓既有 node 物件重建以套用新策略
    }

    public function policy(): array
    {
        return ['policy' => $this->policy, 'maxKeys' => $this->maxKeys];
    }

    // ============ 路由 ============

    private function node(string $shard): CacheNode
    {
        if (!isset($this->nodes[$shard])) {
            $this->nodes[$shard] = new CacheNode($this->dataDir, $shard, $this->maxKeys, $this->policy);
        }
        return $this->nodes[$shard];
    }

    /** 給定 key → 路由資訊（slot、shard） */
    public function route(string $key): array
    {
        $slot = SlotMap::slotOf($key);
        $shard = $this->slotMap->nodeForSlot($slot);
        return ['key' => $key, 'slot' => $slot, 'shard' => $shard];
    }

    public function slotMap(): SlotMap
    {
        return $this->slotMap;
    }

    // ============ 基本 set / get ============

    public function set(string $key, mixed $value, ?int $ttl = null): array
    {
        $route = $this->route($key);
        $res = $this->node($route['shard'])->set($key, $value, $ttl);
        return array_merge($route, $res);
    }

    public function get(string $key): array
    {
        $route = $this->route($key);
        $value = $this->node($route['shard'])->get($key);
        return array_merge($route, ['hit' => $value !== null, 'value' => $value]);
    }

    /** 各 shard 的概觀 */
    public function clusterInfo(): array
    {
        $shards = [];
        foreach ($this->slotMap->nodes() as $shard) {
            $shards[] = $this->node($shard)->info();
        }
        return [
            'policy' => $this->policy,
            'maxKeysPerShard' => $this->maxKeys,
            'ranges' => $this->slotMap->ranges(),
            'shards' => $shards,
            'slotCount' => SlotMap::SLOT_COUNT,
            'dbCalls' => $this->dbCalls(),
        ];
    }

    public function addNode(string $node): array
    {
        return $this->slotMap->addNode($node);
    }

    public function removeNode(string $node): array
    {
        return $this->slotMap->removeNode($node);
    }

    public function failover(string $shard): array
    {
        return $this->node($shard)->failover();
    }

    // ============ DB 回源計數（量化三大問題的回源次數） ============

    private function dbCounterFile(): string
    {
        return $this->dataDir . '/db_calls.txt';
    }

    private function dbCalls(): int
    {
        $f = $this->dbCounterFile();
        return file_exists($f) ? (int) file_get_contents($f) : 0;
    }

    /** 模擬一次「回源查 DB」：累加計數，回傳查到的值（這裡 demo 用固定規則造值） */
    private function hitDb(string $key): mixed
    {
        $fp = fopen($this->dbCounterFile(), 'c+');
        if ($fp !== false) {
            flock($fp, LOCK_EX);
            $n = (int) trim((string) fread($fp, 64));
            $n++;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) $n);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        // 規則：key 以 "missing" 開頭視為 DB 也查無此資料（用於穿透示範）
        if (str_starts_with($key, 'missing')) {
            return null;
        }
        return 'DB_VALUE_OF_' . $key;
    }

    public function resetDbCalls(): void
    {
        file_put_contents($this->dbCounterFile(), '0', LOCK_EX);
    }

    // ============ 三大問題：穿透 / 擊穿 / 雪崩 ============

    /**
     * 統一的「cache-aside 讀」：miss 時回源 DB，可選緩解開關。
     * @param array{nullCache?:bool,bloom?:bool,mutex?:bool} $opts
     */
    private function cacheAsideRead(string $key, array $opts, ?int $ttl = null): array
    {
        $route = $this->route($key);
        $node = $this->node($route['shard']);

        // 命中快取
        $cached = $node->get($key);
        if ($cached !== null) {
            // 空值快取的哨兵
            if ($cached === '__NULL__') {
                return ['key' => $key, 'source' => 'cache(null)', 'value' => null];
            }
            return ['key' => $key, 'source' => 'cache', 'value' => $cached];
        }

        // 布隆過濾器：一定不存在的 key 直接擋掉，不回源
        if (!empty($opts['bloom']) && !$this->bloomMightContain($key)) {
            return ['key' => $key, 'source' => 'bloom-reject', 'value' => null];
        }

        // 互斥鎖 / singleflight：同一 key 同時只放一個回源
        $lockFp = null;
        if (!empty($opts['mutex'])) {
            $lockFp = $this->acquireKeyLock($key);
            // 拿到鎖後再查一次（double-check，可能別人已回填）
            $cached = $node->get($key);
            if ($cached !== null && $cached !== '__NULL__') {
                $this->releaseKeyLock($lockFp);
                return ['key' => $key, 'source' => 'cache(after-lock)', 'value' => $cached];
            }
        }

        // 回源 DB
        $val = $this->hitDb($key);

        if ($val === null) {
            // 穿透緩解：空值也快取一小段時間，避免持續打 DB
            if (!empty($opts['nullCache'])) {
                $node->set($key, '__NULL__', 30);
            }
            $this->releaseKeyLock($lockFp);
            return ['key' => $key, 'source' => 'db(miss)', 'value' => null];
        }

        // 回填快取（雪崩緩解：TTL 加抖動）
        $useTtl = $ttl;
        if ($useTtl !== null && !empty($opts['jitter'])) {
            $useTtl += random_int(0, max(1, intdiv($useTtl, 2)));
        }
        $node->set($key, $val, $useTtl);
        $this->releaseKeyLock($lockFp);
        return ['key' => $key, 'source' => 'db', 'value' => $val];
    }

    /**
     * 穿透示範：對一個「DB 也不存在」的 key 連打 N 次。
     *   緩解關：每次都打 DB；緩解開（nullCache/bloom）：只打第一次（或被 bloom 全擋）。
     */
    public function scenarioPenetration(int $times = 5, bool $mitigate = false): array
    {
        $this->resetDbCalls();
        $key = 'missing-user-' . random_int(1000, 9999);
        if ($mitigate) {
            $this->bloomAdd('user-1'); // bloom 內只有存在的 key；missing-* 不在內 → 被擋
        }
        $opts = $mitigate ? ['nullCache' => true, 'bloom' => true] : [];
        $results = [];
        for ($i = 0; $i < $times; $i++) {
            $r = $this->cacheAsideRead($key, $opts, 60);
            $results[] = $r['source'];
        }
        return [
            'scenario' => 'penetration 穿透',
            'key' => $key,
            'requests' => $times,
            'mitigate' => $mitigate,
            'dbCalls' => $this->dbCalls(),
            'sources' => $results,
            'explain' => $mitigate
                ? '空值快取 + 布隆過濾器：不存在的 key 第一次後即被擋，DB 只被打極少次。'
                : '無緩解：每次查不存在的 key 都打到 DB（dbCalls = 請求數）。',
        ];
    }

    /**
     * 擊穿示範：熱 key 剛過期，N 個併發請求同時回源。
     *   緩解關：N 個請求全部回源；緩解開（mutex/singleflight）：只 1 個回源。
     *   （單程序模擬「同時」：先讓 key 過期，再連續讀。mutex 開時第一個回填後其餘命中。）
     */
    public function scenarioBreakdown(int $concurrency = 5, bool $mitigate = false): array
    {
        $this->resetDbCalls();
        $key = 'hot-key-' . random_int(1000, 9999);
        $route = $this->route($key);
        $node = $this->node($route['shard']);
        // 熱 key「剛過期」：直接刪掉，模擬過期瞬間
        $node->delete($key);

        $sources = [];
        if (!$mitigate) {
            // 無緩解：N 個請求「同時」抵達，都看到 miss（在任何人回填前），於是全部回源。
            // 單程序模擬「同時」：先讓所有請求各自判定 miss、各自回源，最後才回填。
            for ($i = 0; $i < $concurrency; $i++) {
                if (!$node->peekExists($key)) {
                    $this->hitDb($key);          // 每個請求都回源（stampede）
                    $sources[] = 'db';
                } else {
                    $sources[] = 'cache';
                }
            }
            // 回源完成後才回填（晚於所有 miss 判定 → 體現「同時」打 DB）
            $node->set($key, 'hot-reloaded', 60);
        } else {
            // 緩解：singleflight / 互斥鎖 — 同一 key 同時只放一個回源，其餘等鎖後命中。
            for ($i = 0; $i < $concurrency; $i++) {
                $r = $this->cacheAsideRead($key, ['mutex' => true], 60);
                $sources[] = $r['source'];
            }
        }

        return [
            'scenario' => 'breakdown 擊穿',
            'key' => $key,
            'concurrency' => $concurrency,
            'mitigate' => $mitigate,
            'dbCalls' => $this->dbCalls(),
            'sources' => $sources,
            'explain' => $mitigate
                ? 'singleflight/互斥鎖：只有第一個請求回源並回填，其餘命中快取，dbCalls ≈ 1。'
                : '無緩解：熱 key 過期瞬間，每個併發請求都回源，dbCalls = 併發數。',
        ];
    }

    /**
     * 雪崩示範：一次寫入 N 個 key。
     *   緩解關：全部設「相同 TTL」→ 會在同一秒一起過期。
     *   緩解開：TTL 加隨機抖動 → 過期時間打散。
     * 回報這批 key 的到期時間分佈（看是否擠在同一點）。
     */
    public function scenarioAvalanche(int $count = 10, bool $mitigate = false): array
    {
        $baseTtl = 60;
        $expireTimes = [];
        $now = time();
        for ($i = 0; $i < $count; $i++) {
            $key = 'batch-' . random_int(10000, 99999) . '-' . $i;
            $ttl = $baseTtl;
            if ($mitigate) {
                $ttl += random_int(0, 60); // 抖動 0..60 秒
            }
            $this->set($key, 'v' . $i, $ttl);
            $expireTimes[] = $now + $ttl;
        }
        $distinct = count(array_unique($expireTimes));
        return [
            'scenario' => 'avalanche 雪崩',
            'count' => $count,
            'mitigate' => $mitigate,
            'distinctExpireSeconds' => $distinct,
            'spreadSeconds' => max($expireTimes) - min($expireTimes),
            'explain' => $mitigate
                ? 'TTL 加隨機抖動：' . $count . ' 個 key 的到期時間散在 ' . (max($expireTimes) - min($expireTimes)) . ' 秒內，不會同時過期。'
                : '無緩解：' . $count . ' 個 key 同一 TTL → 全部在同一秒過期，過期瞬間回源洪峰打垮 DB。',
        ];
    }

    // ============ 布隆過濾器（教學版：bitset + 多個 crc32 雜湊） ============

    private function bloomFile(): string
    {
        return $this->dataDir . '/bloom.json';
    }

    private function bloomBits(): array
    {
        $f = $this->bloomFile();
        if (!file_exists($f)) {
            return [];
        }
        $arr = json_decode((string) file_get_contents($f), true);
        return is_array($arr) ? $arr : [];
    }

    private const BLOOM_SIZE = 1024;

    /** @return list<int> 三個 bit 位置 */
    private function bloomPositions(string $key): array
    {
        return [
            crc32('a:' . $key) % self::BLOOM_SIZE,
            crc32('b:' . $key) % self::BLOOM_SIZE,
            crc32('c:' . $key) % self::BLOOM_SIZE,
        ];
    }

    public function bloomAdd(string $key): void
    {
        $bits = $this->bloomBits();
        foreach ($this->bloomPositions($key) as $p) {
            $bits[(string) $p] = 1;
        }
        file_put_contents($this->bloomFile(), json_encode($bits), LOCK_EX);
    }

    /** 可能存在 → true（可能誤判為存在）；一定不存在 → false（不會誤判為不存在） */
    public function bloomMightContain(string $key): bool
    {
        $bits = $this->bloomBits();
        foreach ($this->bloomPositions($key) as $p) {
            if (empty($bits[(string) $p])) {
                return false;
            }
        }
        return true;
    }

    // ============ 鍵級鎖（singleflight / 互斥鎖） ============

    private function acquireKeyLock(string $key)
    {
        $safe = preg_replace('/[^0-9A-Za-z_\-]/', '_', $key) ?? 'k';
        $fp = fopen($this->dataDir . '/lock_' . $safe . '.lock', 'c');
        if ($fp !== false) {
            flock($fp, LOCK_EX);
        }
        return $fp;
    }

    private function releaseKeyLock($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
