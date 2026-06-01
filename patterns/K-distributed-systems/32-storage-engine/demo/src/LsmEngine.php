<?php
declare(strict_types=1);

require_once __DIR__ . '/Bloom.php';

/**
 * LSM-tree 儲存引擎（Log-Structured Merge-tree）
 * ==================================================
 * 寫密集型 KV 引擎的核心結構，RocksDB / LevelDB / Cassandra / HBase 都用它。
 *
 * 寫路徑（為何寫很快）：
 *   1. WAL：先把這次寫 append 到 write-ahead log 檔（順序寫，崩潰可復原）。
 *   2. memtable：再寫進記憶體中的「有序表」（key→value，刪除用 tombstone 標記）。
 *      → 一次寫只碰「順序 append 一筆日誌 + 改記憶體」，沒有隨機寫磁碟，所以快。
 *   3. flush：memtable 超過門檻 → 整批排序後寫成一個「不可變」的 SSTable 檔，
 *      連同稀疏索引與 bloom filter；然後清空 memtable、截斷 WAL。
 *
 * 讀路徑（為何要多花工）：
 *   get(key)：先查 memtable（最新）→ 再由新到舊掃每個 SSTable，
 *   每個 SSTable 先問它的 bloom filter「可能有嗎」，false 就跳過（省磁碟 I/O）；
 *   第一個命中的版本即為答案（含 tombstone → 代表已刪除）。
 *   一個 key 可能散落在多個 SSTable（舊版本），這就是「讀放大」。
 *
 * compaction（壓實）：
 *   背景把多個 SSTable 合併成更少、更大的檔，丟掉被覆寫/被刪除的舊版本，
 *   降低讀放大與空間放大。本實作用簡化版 size-tiered：把目前所有 SSTable 合成一個。
 *
 * 對比 B-tree（見 notes §8）：
 *   B-tree 就地更新 → 隨機寫、寫放大高，但讀只需一次樹查找（讀放大低）。
 *   LSM 只追加 → 寫快、寫放大低，但讀要查多層 + compaction 吃 IO。
 *
 * 持久化：WAL / SSTable 都是檔案；memtable 為記憶體，重啟時由 WAL 重播復原。
 * 共享狀態用 flock 保護整個引擎目錄，確保並行請求不互踩。
 */
final class LsmEngine
{
    /** 刪除標記：memtable / SSTable 中 value 為此值代表「此 key 已被刪除」 */
    public const TOMBSTONE = "\x00__TOMBSTONE__\x00";

    private string $dir;
    private string $walFile;
    private string $lockFile;
    private string $manifestFile;

    /** memtable flush 門檻（筆數），刻意設小以便 demo 觸發 */
    private int $memtableLimit;

    /** @var array<string,string> 記憶體有序表（key→value 或 TOMBSTONE） */
    private array $memtable = [];

    /** 統計：bloom 跳過 / 實際讀檔次數 */
    private int $bloomSkips = 0;
    private int $sstableReads = 0;

    public function __construct(string $dir, int $memtableLimit = 4)
    {
        $this->dir = rtrim($dir, "/\\");
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        $this->walFile = $this->dir . '/wal.log';
        $this->lockFile = $this->dir . '/engine.lock';
        $this->manifestFile = $this->dir . '/manifest.json';
        $this->memtableLimit = max(1, $memtableLimit);

        if (!file_exists($this->manifestFile)) {
            $this->writeManifest(['sstables' => [], 'seq' => 0]);
        }
        // 啟動時由 WAL 重播未 flush 的寫入（崩潰復原）
        $this->recoverFromWal();
    }

    // ============ 寫入 ============

    /** PUT：WAL → memtable，必要時觸發 flush */
    public function put(string $key, string $value): void
    {
        $this->withLock(function () use ($key, $value): void {
            $this->appendWal('P', $key, $value);
            $this->memtable[$key] = $value;
            ksort($this->memtable, SORT_STRING); // 維持有序
            if (count($this->memtable) >= $this->memtableLimit) {
                $this->flushLocked();
            }
        });
    }

    /** DELETE：寫入 tombstone（LSM 的刪除不是真的清掉，而是標記） */
    public function delete(string $key): void
    {
        $this->withLock(function () use ($key): void {
            $this->appendWal('D', $key, self::TOMBSTONE);
            $this->memtable[$key] = self::TOMBSTONE;
            ksort($this->memtable, SORT_STRING);
            if (count($this->memtable) >= $this->memtableLimit) {
                $this->flushLocked();
            }
        });
    }

    // ============ 讀取 ============

    /**
     * GET：memtable → 由新到舊掃 SSTable（bloom 先過濾）。
     * 回傳 null = 不存在或已刪除（tombstone）。
     */
    public function get(string $key): ?string
    {
        return $this->withLock(function () use ($key): ?string {
            // 1) 先查 memtable（最新版本）
            if (array_key_exists($key, $this->memtable)) {
                $v = $this->memtable[$key];
                return $v === self::TOMBSTONE ? null : $v;
            }
            // 2) 由新到舊掃 SSTable
            $manifest = $this->readManifest();
            $sstables = array_reverse($manifest['sstables']); // 新 → 舊
            foreach ($sstables as $meta) {
                $bloom = Bloom::fromArray($meta['bloom']);
                if (!$bloom->mightContain($key)) {
                    $this->bloomSkips++; // bloom 確定不在 → 不碰磁碟
                    continue;
                }
                $this->sstableReads++;
                $data = $this->readSstable($meta['file']);
                if (array_key_exists($key, $data)) {
                    $v = $data[$key];
                    return $v === self::TOMBSTONE ? null : $v; // 第一個命中即答案
                }
                // bloom 說可能有但其實沒有 → 偽陽性，繼續找更舊的
            }
            return null;
        });
    }

    // ============ flush / compaction ============

    /** 手動觸發 flush（memtable → SSTable） */
    public function flush(): bool
    {
        return $this->withLock(fn (): bool => $this->flushLocked());
    }

    /** 已持鎖狀態下執行 flush */
    private function flushLocked(): bool
    {
        if ($this->memtable === []) {
            return false;
        }
        $manifest = $this->readManifest();
        $seq = (int) $manifest['seq'] + 1;
        $file = sprintf('sstable_%04d.json', $seq);

        // 建 bloom filter：放入此 SSTable 的所有 key
        $bloom = new Bloom(1024, 4);
        foreach (array_keys($this->memtable) as $k) {
            $bloom->add((string) $k);
        }

        // 稀疏索引：每隔幾個 key 記一個（真實 LSM 用來定位 block；此處示意）
        $keys = array_keys($this->memtable);
        $sparse = [];
        $step = max(1, (int) floor(count($keys) / 4));
        for ($i = 0; $i < count($keys); $i += $step) {
            $sparse[(string) $keys[$i]] = $i;
        }

        $this->writeSstable($file, $this->memtable);

        $manifest['sstables'][] = [
            'file' => $file,
            'seq' => $seq,
            'count' => count($this->memtable),
            'min_key' => (string) $keys[0],
            'max_key' => (string) $keys[count($keys) - 1],
            'sparse_index' => $sparse,
            'bloom' => $bloom->toArray(),
        ];
        $manifest['seq'] = $seq;
        $this->writeManifest($manifest);

        // 清空 memtable、截斷 WAL（資料已落到不可變 SSTable）
        $this->memtable = [];
        file_put_contents($this->walFile, '');
        return true;
    }

    /**
     * compaction（簡化版 size-tiered）：
     *   把目前所有 SSTable 由舊到新合併成「一個」新 SSTable，
     *   新版本覆蓋舊版本，tombstone 代表的 key 直接丟棄（不再保留），
     *   藉此降低讀放大（要掃的檔變少）與空間放大（過期版本被清掉）。
     */
    public function compact(): array
    {
        return $this->withLock(function (): array {
            $manifest = $this->readManifest();
            $sstables = $manifest['sstables'];
            if (count($sstables) < 2) {
                return ['merged' => false, 'reason' => 'SSTable 少於 2 個，無需 compaction'];
            }

            // 由舊到新合併：新值覆蓋舊值（後者勝）
            $merged = [];
            foreach ($sstables as $meta) {
                $data = $this->readSstable($meta['file']);
                foreach ($data as $k => $v) {
                    $merged[$k] = $v; // 較新的 SSTable 後處理 → 覆蓋
                }
            }

            $beforeKeys = count($merged);
            // 丟掉 tombstone：壓實後這些 key 視為徹底刪除
            $merged = array_filter($merged, fn ($v): bool => $v !== self::TOMBSTONE);
            ksort($merged, SORT_STRING);
            $droppedTombstones = $beforeKeys - count($merged);

            // 寫成單一新 SSTable
            $seq = (int) $manifest['seq'] + 1;
            $file = sprintf('sstable_%04d.json', $seq);
            $bloom = new Bloom(1024, 4);
            foreach (array_keys($merged) as $k) {
                $bloom->add((string) $k);
            }
            $keys = array_keys($merged);
            $this->writeSstable($file, $merged);

            // 刪掉舊 SSTable 檔
            $oldFiles = array_column($sstables, 'file');
            foreach ($oldFiles as $of) {
                $p = $this->dir . '/' . $of;
                if (is_file($p)) {
                    @unlink($p);
                }
            }

            $manifest['sstables'] = [[
                'file' => $file,
                'seq' => $seq,
                'count' => count($merged),
                'min_key' => $keys === [] ? '' : (string) $keys[0],
                'max_key' => $keys === [] ? '' : (string) $keys[count($keys) - 1],
                'sparse_index' => [],
                'bloom' => $bloom->toArray(),
            ]];
            $manifest['seq'] = $seq;
            $this->writeManifest($manifest);

            return [
                'merged' => true,
                'input_sstables' => count($sstables),
                'output_sstables' => 1,
                'live_keys' => count($merged),
                'dropped_tombstones' => $droppedTombstones,
            ];
        });
    }

    // ============ 狀態查詢（供測試頁） ============

    public function state(): array
    {
        return $this->withLock(function (): array {
            $manifest = $this->readManifest();
            $mem = [];
            foreach ($this->memtable as $k => $v) {
                $mem[$k] = $v === self::TOMBSTONE ? '⟨已刪除 tombstone⟩' : $v;
            }
            $sst = [];
            foreach ($manifest['sstables'] as $meta) {
                $sst[] = [
                    'file' => $meta['file'],
                    'count' => $meta['count'],
                    'min_key' => $meta['min_key'],
                    'max_key' => $meta['max_key'],
                ];
            }
            return [
                'memtable' => $mem,
                'memtable_size' => count($this->memtable),
                'memtable_limit' => $this->memtableLimit,
                'sstables' => $sst,
                'sstable_count' => count($sst),
                'wal_bytes' => is_file($this->walFile) ? filesize($this->walFile) : 0,
                'bloom_skips' => $this->bloomSkips,
                'sstable_reads' => $this->sstableReads,
            ];
        });
    }

    // ============ WAL ============

    private function appendWal(string $op, string $key, string $value): void
    {
        $line = json_encode(
            ['op' => $op, 'key' => $key, 'value' => $value],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        file_put_contents($this->walFile, $line . "\n", FILE_APPEND);
    }

    /** 崩潰復原：重播 WAL 中尚未 flush 的寫入回 memtable */
    private function recoverFromWal(): void
    {
        if (!is_file($this->walFile)) {
            return;
        }
        $fp = fopen($this->walFile, 'r');
        if ($fp === false) {
            return;
        }
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $rec = json_decode($line, true);
            if (!is_array($rec) || !isset($rec['key'])) {
                continue;
            }
            $this->memtable[(string) $rec['key']] = (string) ($rec['value'] ?? self::TOMBSTONE);
        }
        fclose($fp);
        if ($this->memtable !== []) {
            ksort($this->memtable, SORT_STRING);
        }
    }

    // ============ SSTable / manifest I/O ============

    private function writeSstable(string $file, array $data): void
    {
        $path = $this->dir . '/' . $file;
        file_put_contents(
            $path,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    private function readSstable(string $file): array
    {
        $path = $this->dir . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $json = (string) file_get_contents($path);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function readManifest(): array
    {
        $json = (string) file_get_contents($this->manifestFile);
        $m = json_decode($json, true);
        if (!is_array($m)) {
            return ['sstables' => [], 'seq' => 0];
        }
        $m['sstables'] ??= [];
        $m['seq'] ??= 0;
        return $m;
    }

    private function writeManifest(array $manifest): void
    {
        file_put_contents(
            $this->manifestFile,
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    // ============ 鎖 ============

    /**
     * 用 flock 包住整個操作，確保並行 HTTP 請求對引擎狀態的存取互斥。
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withLock(callable $fn): mixed
    {
        $fp = fopen($this->lockFile, 'c');
        if ($fp === false) {
            return $fn();
        }
        flock($fp, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
