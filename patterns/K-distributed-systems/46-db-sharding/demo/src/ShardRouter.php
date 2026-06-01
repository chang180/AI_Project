<?php
declare(strict_types=1);

/**
 * 關聯式資料庫分片路由器（Sharding / Vitess 風格）
 * ==============================================================
 * 本題 demo 的核心：把「一張大表」水平切到「多台 MySQL 分片」上。
 * 為了能在單一 PHP process 跑起來，這裡用「每個分片一個 JSON 檔」
 * 假裝成一台獨立的 MySQL；真正在跑的演算法都是分片系統的真考點：
 *
 *   1) 分片路由      shard = crc32(shard_key) % N        —— 帶 shard_key 的單片查詢
 *   2) scatter-gather 跨分片查詢（範圍 / 聚合）要打「所有分片」再合併排序
 *   3) 分散式自增 ID  跨分片不能用單一 auto_increment → 用「號段」讓各分片不撞號
 *   4) re-shard       加一台分片時，部分 key 要「換家」搬遷（並算出影響範圍）
 *
 * ⚠️ 與第 ㉛ 題（分散式 KV，一致性雜湊）刻意對比：
 *   - KV 用「一致性雜湊環 + vnode」，加機器只搬 ~1/N，且天生無 JOIN/交易。
 *   - 本題用「hash(key) % N」這種「取模分片」（很多關聯式分片中介層的預設），
 *     凸顯關聯式分片的痛：① 取模分片加機器幾乎全搬（re-shard 很痛）
 *                         ② 沒帶 shard_key 的查詢只能 scatter-gather（跨片 JOIN/聚合很貴）
 *                         ③ 自增主鍵跨片會撞號（要全域發號）
 *
 * 環境限制：PHP 8.4 原生、零依賴；僅用 json + hash(crc32)。每檔 declare(strict_types=1)。
 *           各分片 JSON 檔以 flock 原子化寫入 data/。未使用 mb_、gmp_、openssl_、sqlite、PDO。
 */
final class ShardRouter
{
    private string $dir;
    private string $metaFile;

    /** @var array{shards:int,segment:int,id_alloc:array<int,int>} */
    private array $meta;

    public function __construct(string $dataDir)
    {
        $this->dir = rtrim($dataDir, '/\\');
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        $this->metaFile = $this->dir . '/meta.json';
        $this->loadMeta();
    }

    // ===================== meta（分片數 / 號段 / 各分片發號水位） =====================

    private function defaultMeta(): array
    {
        return [
            // 分片數 N：水平切成幾台 MySQL。預設 2 片，方便示範 re-shard 加到 3 片。
            'shards'   => 2,
            // 號段大小：全域發號器一次「批給」某分片的 ID 區段大小（號段/segment 法）。
            'segment'  => 1000,
            // 每個分片目前領到的「下一個可用本地 ID」（用來示範各分片不撞號）。
            'id_alloc' => [],
        ];
    }

    private function loadMeta(): void
    {
        if (!file_exists($this->metaFile)) {
            $this->meta = $this->defaultMeta();
            $this->saveMeta();
            return;
        }
        $m = json_decode((string) file_get_contents($this->metaFile), true);
        $this->meta = is_array($m) ? $m + $this->defaultMeta() : $this->defaultMeta();
    }

    private function saveMeta(): void
    {
        $this->atomicWrite($this->metaFile, $this->meta);
    }

    /** flock 原子化寫入 JSON */
    private function atomicWrite(string $file, array $data): void
    {
        file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    public function shardCount(): int
    {
        return (int) $this->meta['shards'];
    }

    public function segmentSize(): int
    {
        return (int) $this->meta['segment'];
    }

    /** 設定分片數（會清空既有資料重來——示範用，真實系統不能這樣）。 */
    public function setShards(int $n): void
    {
        $n = max(1, min(8, $n));
        // 砍掉所有分片檔，重置發號水位
        for ($i = 0; $i < 16; $i++) {
            $f = $this->shardFile($i);
            if (file_exists($f)) {
                @unlink($f);
            }
        }
        $this->meta['shards'] = $n;
        $this->meta['id_alloc'] = [];
        $this->saveMeta();
    }

    // ===================== 分片路由（核心 #1） =====================

    /**
     * 取模分片：shard = crc32(shard_key) % N
     * 這就是「依 shard_key 算出資料該放哪一台 MySQL」。
     * 帶 shard_key 的查詢可直接命中單一分片（最理想，O(1) 路由）。
     */
    public function shardOf(string $shardKey): int
    {
        return ($this->crc($shardKey) % $this->shardCount());
    }

    private function crc(string $s): int
    {
        // crc32 在 64-bit PHP 可能為負，& 0xFFFFFFFF 轉無號
        return crc32($s) & 0xFFFFFFFF;
    }

    private function shardFile(int $shard): string
    {
        return $this->dir . '/shard_' . $shard . '.json';
    }

    /**
     * 讀取某分片的「整張表」。
     * @return array<int,array<string,mixed>> rows（每筆是一個關聯陣列）
     */
    private function readShard(int $shard): array
    {
        $f = $this->shardFile($shard);
        if (!file_exists($f)) {
            return [];
        }
        $rows = json_decode((string) file_get_contents($f), true);
        return is_array($rows) ? $rows : [];
    }

    private function writeShard(int $shard, array $rows): void
    {
        $this->atomicWrite($this->shardFile($shard), array_values($rows));
    }

    // ===================== 分散式自增 ID（核心 #3） =====================

    /**
     * 全域唯一 ID（號段/segment 法）：
     * 跨分片不能各自 auto_increment（會撞號）。做法是「全域發號器」把整個
     * ID 空間切成不重疊的號段，依「分片 × 號段」分配，保證每個分片拿到的
     * 區間互不重疊 → 各分片本地遞增也永遠不撞號。
     *
     * 這裡的編碼：globalId = shard * SEGMENT + localSeq
     *   - SEGMENT=1000 時：分片 0 用 0~999、分片 1 用 1000~1999、分片 2 用 2000~2999…
     *   - 每用滿一個號段，就再批一個「N*SEGMENT 之後」的新號段（示範略，水位持續往上加）。
     * 真實 Vitess / 號段服務（Leaf）做法相同：批量領段、本地遞增、用完再領，
     * 避免每筆寫入都去搶一個全域計數器（那會變成熱點瓶頸）。
     */
    public function nextId(int $shard): int
    {
        $alloc = $this->meta['id_alloc'];
        $seq = isset($alloc[$shard]) ? (int) $alloc[$shard] : 0;
        $alloc[$shard] = $seq + 1;
        $this->meta['id_alloc'] = $alloc;
        $this->saveMeta();
        // 全域 ID：分片號段 + 本地序號 → 跨分片永不重複
        return $shard * $this->segmentSize() + $seq;
    }

    // ===================== insert（路由到對的分片） =====================

    /**
     * 依 shard_key 路由插入。回傳含「落在哪片、配到的全域 ID」。
     * @param array<string,mixed> $extra 其他欄位（如 amount、region）
     */
    public function insert(string $shardKey, array $extra = []): array
    {
        $shard = $this->shardOf($shardKey);
        $id = $this->nextId($shard);
        $rows = $this->readShard($shard);
        $row = ['id' => $id, 'shard_key' => $shardKey] + $extra;
        $rows[] = $row;
        $this->writeShard($shard, $rows);

        return [
            'ok'        => true,
            'routed_to' => $shard,
            'formula'   => "crc32('{$shardKey}') % {$this->shardCount()} = {$shard}",
            'global_id' => $id,
            'row'       => $row,
        ];
    }

    // ===================== get：帶 shard_key 的單片查詢（核心 #1） =====================

    /**
     * 帶 shard_key → 只打「一台」分片（理想路徑）。
     * 回傳該 shard_key 命中的所有列 + 走訪了幾片（這裡=1，凸顯單片查詢之快）。
     */
    public function getByKey(string $shardKey): array
    {
        $shard = $this->shardOf($shardKey);
        $hits = [];
        foreach ($this->readShard($shard) as $row) {
            if (($row['shard_key'] ?? null) === $shardKey) {
                $hits[] = $row;
            }
        }
        return [
            'ok'             => true,
            'shard_key'      => $shardKey,
            'routed_to'      => $shard,
            'shards_scanned' => 1,                 // 單片！只碰一台 MySQL
            'rows'           => $hits,
        ];
    }

    // ===================== aggregate：scatter-gather（核心 #2） =====================

    /**
     * 沒帶 shard_key 的查詢（範圍 / 排序 / 聚合）→ 只能 scatter-gather：
     *   scatter：把同一條查詢「廣播到所有分片」各自執行
     *   gather ：把每片的結果「拉回中介層合併」（排序 / 加總 / 去重）
     *
     * 這正是關聯式分片最痛的地方：一旦查詢條件不含 shard_key，就退化成
     * 「打 N 台、再合併」，延遲取決於最慢那片，且跨片 JOIN / GROUP BY /
     * ORDER BY LIMIT 都要在中介層二次計算，成本遠高於單機一句 SQL。
     *
     * 這裡示範三種聚合：count / sum(amount) / 依 amount 排序的 top-k。
     * @param array{op?:string,field?:string,limit?:int} $opts
     */
    public function aggregate(array $opts = []): array
    {
        $op    = (string) ($opts['op'] ?? 'count');   // count | sum | top
        $field = (string) ($opts['field'] ?? 'amount');
        $limit = (int) ($opts['limit'] ?? 5);
        $n     = $this->shardCount();

        $perShard = [];        // 每片的部分結果（示範 scatter 的中間產物）
        $allRows  = [];        // gather 用：把每片的列拉回來
        $count    = 0;
        $sum      = 0.0;

        // ---- scatter：對「每一台分片」各跑一次 ----
        for ($s = 0; $s < $n; $s++) {
            $rows = $this->readShard($s);
            $cCount = count($rows);
            $cSum = 0.0;
            foreach ($rows as $row) {
                $cSum += (float) ($row[$field] ?? 0);
                $allRows[] = $row + ['_shard' => $s];
            }
            $count += $cCount;
            $sum   += $cSum;
            $perShard[] = ['shard' => $s, 'count' => $cCount, 'sum' => round($cSum, 2)];
        }

        // ---- gather：把各片部分結果合併成最終答案 ----
        $result = ['op' => $op, 'shards_scanned' => $n, 'per_shard' => $perShard];
        if ($op === 'count') {
            $result['total'] = $count;
        } elseif ($op === 'sum') {
            $result['total'] = round($sum, 2);
        } else { // top：跨片排序 + 取前 k（中介層做的二次排序，凸顯 ORDER BY LIMIT 之痛）
            usort($allRows, static fn(array $a, array $b): int => ($b[$field] ?? 0) <=> ($a[$field] ?? 0));
            $result['top'] = array_slice($allRows, 0, max(1, $limit));
        }
        $result['note'] = "沒帶 shard_key → 打了全部 {$n} 片再合併（scatter-gather），延遲取決於最慢那片。";
        return $result;
    }

    // ===================== re-shard：加一台分片（核心 #4） =====================

    /**
     * 加一台分片：N → N+1。
     * 取模分片（hash % N）的致命傷：N 一變，幾乎每個 key 的 (hash % N) 都變 →
     * 大部分資料都要「換家」搬遷。這裡：
     *   1) 先計算每筆資料「舊片 → 新片」，找出真正要搬的（換家的）
     *   2) 實際把要搬的列從舊分片移到新分片
     *   3) 回報搬遷比例，凸顯 re-shard 的高成本
     *
     * 對比：一致性雜湊（㉛）加機器只搬 ~1/N；取模分片可能搬到 ~ (N)/(N+1)。
     * 真實系統因此偏好「一致性雜湊 / 範圍分片 + 預切足夠多的 shard」來降低搬遷量；
     * Vitess 的 reshard 也是線上複製 + 切流，而非停機全搬。
     */
    public function addShard(): array
    {
        $oldN = $this->shardCount();
        $newN = $oldN + 1;
        if ($newN > 8) {
            return ['ok' => false, 'msg' => '示範上限 8 片'];
        }

        // 1) 收集所有列 + 標出舊片
        $all = [];
        for ($s = 0; $s < $oldN; $s++) {
            foreach ($this->readShard($s) as $row) {
                $all[] = ['row' => $row, 'old' => $s];
            }
        }

        // 2) 用新 N 重新計算落點，分出「要搬」與「留下」
        $newShards = array_fill(0, $newN, []);
        $moved = [];
        $total = count($all);
        foreach ($all as $item) {
            $key = (string) ($item['row']['shard_key'] ?? '');
            $newShard = $this->crc($key) % $newN;
            $newShards[$newShard][] = $item['row'];
            if ($newShard !== $item['old']) {
                $moved[] = [
                    'shard_key' => $key,
                    'id'        => $item['row']['id'] ?? null,
                    'from'      => $item['old'],
                    'to'        => $newShard,
                ];
            }
        }

        // 3) 落地：分片數先生效，再覆寫每一片
        $this->meta['shards'] = $newN;
        // 新分片沿用「分片號段」發號，水位從 0 起算（新片號段不與舊片重疊）
        if (!isset($this->meta['id_alloc'][$oldN])) {
            $this->meta['id_alloc'][$oldN] = 0;
        }
        $this->saveMeta();
        for ($s = 0; $s < $newN; $s++) {
            $this->writeShard($s, $newShards[$s]);
        }

        $movePct = $total > 0 ? round(count($moved) / $total * 100, 1) : 0.0;
        return [
            'ok'           => true,
            'from_shards'  => $oldN,
            'to_shards'    => $newN,
            'total_rows'   => $total,
            'moved_rows'   => count($moved),
            'moved_pct'    => $movePct,
            'moved'        => array_slice($moved, 0, 20),  // 只列前 20 筆「換家」明細
            'note'         => "取模分片 N {$oldN}→{$newN}：{$movePct}% 的資料換家。對比一致性雜湊只搬 ~1/N。",
        ];
    }

    // ===================== 整體狀態（給測試頁用） =====================

    public function overview(): array
    {
        $n = $this->shardCount();
        $shards = [];
        $sampleRows = [];
        for ($s = 0; $s < $n; $s++) {
            $rows = $this->readShard($s);
            $shards[] = ['shard' => $s, 'rows' => count($rows)];
            foreach ($rows as $row) {
                $sampleRows[] = $row + ['_shard' => $s];
            }
        }
        // 依全域 ID 排序，方便看分佈
        usort($sampleRows, static fn(array $a, array $b): int => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        return [
            'shards'      => $n,
            'segment'     => $this->segmentSize(),
            'per_shard'   => $shards,
            'sample_rows' => array_slice($sampleRows, 0, 60),
        ];
    }
}
