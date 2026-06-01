<?php
declare(strict_types=1);

require_once __DIR__ . '/Erasure.php';

/**
 * 物件儲存 ObjectStore（教學版，單機檔案模擬 S3 核心邏輯）
 * ---------------------------------------------------------------
 * 真實 S3：海量分散式儲存節點、獨立的 metadata 服務、跨 AZ/Region 複製、
 * 背景修復(repair)、生命週期(lifecycle)、IAM。
 *
 * 這裡用 data/ 下的子目錄模擬「儲存節點」，每個物件的分片/副本依
 * 「一致性雜湊」放到不同節點。以下邏輯都是真的、可實際執行：
 *   - 分段上傳 multipart：init → upload part → complete 組裝，算 ETag
 *   - 一致性雜湊：把分片 key 對映到雜湊環上順時針第一個節點
 *   - 放置策略：REPLICA（副本 R 份）或 EC（抹除碼 k+m 分片）
 *   - metadata 服務：bucket/key → manifest（parts、放置、size、etag、策略）
 *   - 節點故障還原：標記節點失效後仍能用副本或 parity 還原並比對內容
 *   - 預簽 URL：hash_hmac 簽含到期時間的 URL，驗章 + 未過期才放行
 *
 * 所有共享狀態寫入用 flock 保護，支援多請求併發。
 */
final class ObjectStore
{
    private string $dataDir;
    private string $nodesDir;
    /** @var list<string> 模擬的儲存節點名稱 */
    private array $nodes;
    /** 預簽 URL 用的密鑰（真實系統來自存取金鑰，這裡寫死示意） */
    private string $signingKey = 'demo-secret-key-please-rotate';

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/\\');
        $this->nodesDir = $this->dataDir . '/nodes';
        if (!is_dir($this->nodesDir)) {
            mkdir($this->nodesDir, 0777, true);
        }
        // 6 個儲存節點
        $this->nodes = ['node-a', 'node-b', 'node-c', 'node-d', 'node-e', 'node-f'];
        foreach ($this->nodes as $n) {
            $d = $this->nodesDir . '/' . $n;
            if (!is_dir($d)) {
                mkdir($d, 0777, true);
            }
        }
    }

    // ============ 一致性雜湊放置 ============

    /**
     * 一致性雜湊：每個節點放 V 個虛擬節點到 0..2^32 的環上，
     * 物件分片 key 雜湊後，順時針找第一個（含繞回）的節點。
     * 連續取 count 個「不同實體節點」，作為副本/分片的落點。
     *
     * @return list<string> 去重後的實體節點名稱（長度 = count）
     */
    public function placeNodes(string $shardKey, int $count): array
    {
        $vnodes = $this->ring();             // 已排序：position => node
        $positions = array_keys($vnodes);    // 升冪
        $h = $this->ringHash($shardKey);

        // 在環上找第一個 >= h 的位置
        $start = 0;
        foreach ($positions as $idx => $pos) {
            if ($pos >= $h) {
                $start = $idx;
                break;
            }
        }

        $picked = [];
        $total = count($positions);
        for ($i = 0; $i < $total && count($picked) < $count; $i++) {
            $node = $vnodes[$positions[($start + $i) % $total]];
            if (!in_array($node, $picked, true)) {
                $picked[] = $node;
            }
        }
        return $picked;
    }

    /** 建立雜湊環（節點 × 虛擬節點數）。 */
    private function ring(): array
    {
        $vPerNode = 16;
        $ring = [];
        foreach ($this->nodes as $node) {
            for ($v = 0; $v < $vPerNode; $v++) {
                $ring[$this->ringHash($node . '#' . $v)] = $node;
            }
        }
        ksort($ring);
        return $ring;
    }

    /** 用 crc32 當環上位置（0..2^32-1），符合「禁用 gmp，校驗和用 crc32」限制。 */
    private function ringHash(string $s): int
    {
        return crc32($s) & 0xFFFFFFFF;
    }

    // ============ 節點失效模擬 ============

    private function failFile(): string
    {
        return $this->dataDir . '/failed_nodes.json';
    }

    /** @return list<string> 目前被標記失效的節點 */
    public function failedNodes(): array
    {
        $f = $this->failFile();
        if (!file_exists($f)) {
            return [];
        }
        $arr = json_decode((string) file_get_contents($f), true);
        return is_array($arr) ? array_values($arr) : [];
    }

    public function setNodeFailed(string $node, bool $failed): array
    {
        $cur = $this->failedNodes();
        if ($failed) {
            if (!in_array($node, $cur, true) && in_array($node, $this->nodes, true)) {
                $cur[] = $node;
            }
        } else {
            $cur = array_values(array_filter($cur, static fn($n) => $n !== $node));
        }
        file_put_contents($this->failFile(), json_encode(array_values($cur)), LOCK_EX);
        return $cur;
    }

    public function isNodeUp(string $node): bool
    {
        return !in_array($node, $this->failedNodes(), true);
    }

    public function nodes(): array
    {
        return $this->nodes;
    }

    // ============ 節點上的 block 讀寫（模擬「儲存節點」） ============

    private function blockPath(string $node, string $blockId): string
    {
        return $this->nodesDir . '/' . $node . '/' . $blockId . '.blk';
    }

    private function writeBlock(string $node, string $blockId, string $bytes): void
    {
        file_put_contents($this->blockPath($node, $blockId), $bytes, LOCK_EX);
    }

    /** 從節點讀 block；若節點失效或不存在則回 null（模擬讀不到）。 */
    private function readBlock(string $node, string $blockId): ?string
    {
        if (!$this->isNodeUp($node)) {
            return null;
        }
        $p = $this->blockPath($node, $blockId);
        if (!file_exists($p)) {
            return null;
        }
        return (string) file_get_contents($p);
    }

    // ============ Multipart 上傳 ============

    private function uploadsFile(): string
    {
        return $this->dataDir . '/uploads.json';
    }

    private function readUploads(): array
    {
        $f = $this->uploadsFile();
        if (!file_exists($f)) {
            return [];
        }
        $a = json_decode((string) file_get_contents($f), true);
        return is_array($a) ? $a : [];
    }

    private function writeUploads(array $u): void
    {
        file_put_contents($this->uploadsFile(), json_encode($u, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /** init multipart：回傳 uploadId。 */
    public function initUpload(string $bucket, string $key): string
    {
        $uploads = $this->readUploads();
        $uploadId = substr(md5($bucket . '/' . $key . '/' . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 16);
        $uploads[$uploadId] = [
            'bucket' => $bucket,
            'key' => $key,
            'parts' => [],   // partNumber => md5
            'data' => [],    // partNumber => 原始位元組（教學版暫存於 metadata，真實系統存節點）
        ];
        $this->writeUploads($uploads);
        return $uploadId;
    }

    /** 上傳一個 part：回傳該 part 的 md5（即 part ETag）。 */
    public function uploadPart(string $uploadId, int $partNumber, string $bytes): string
    {
        $uploads = $this->readUploads();
        if (!isset($uploads[$uploadId])) {
            throw new RuntimeException('uploadId 不存在');
        }
        $partMd5 = md5($bytes);
        $uploads[$uploadId]['parts'][(string) $partNumber] = $partMd5;
        $uploads[$uploadId]['data'][(string) $partNumber] = base64_encode($bytes);
        $this->writeUploads($uploads);
        return $partMd5;
    }

    /**
     * complete multipart：依 partNumber 排序組裝成完整物件，
     * 算 multipart ETag（各 part 的 md5 binary 串接後再 md5，附 -partCount），
     * 依放置策略把資料放到節點，寫 manifest。
     *
     * @return array manifest
     */
    public function completeUpload(string $uploadId, string $strategy, int $replicas = 3): array
    {
        $uploads = $this->readUploads();
        if (!isset($uploads[$uploadId])) {
            throw new RuntimeException('uploadId 不存在');
        }
        $u = $uploads[$uploadId];
        $partNumbers = array_map('intval', array_keys($u['parts']));
        sort($partNumbers);

        $object = '';
        $partMd5Concat = '';
        $partList = [];
        foreach ($partNumbers as $pn) {
            $bytes = base64_decode((string) $u['data'][(string) $pn]);
            $object .= $bytes;
            $md5 = (string) $u['parts'][(string) $pn];
            $partMd5Concat .= hex2bin($md5);
            $partList[] = ['partNumber' => $pn, 'etag' => $md5, 'size' => strlen($bytes)];
        }
        // S3 風格 multipart ETag：md5(各 part md5 binary 串接)-part 數
        $etag = md5($partMd5Concat) . '-' . count($partNumbers);
        $size = strlen($object);
        $checksum = sprintf('%08x', crc32($object)); // 整體校驗和（crc32）

        $bucket = (string) $u['bucket'];
        $key = (string) $u['key'];
        $objId = md5($bucket . '/' . $key);

        $placement = $this->placeAndStore($objId, $object, $strategy, $replicas);

        $manifest = [
            'bucket' => $bucket,
            'key' => $key,
            'size' => $size,
            'etag' => $etag,
            'crc32' => $checksum,
            'strategy' => $strategy,
            'replicas' => $strategy === 'REPLICA' ? $replicas : null,
            'erasure' => $strategy === 'EC' ? ['k' => Erasure::K, 'm' => Erasure::M] : null,
            'parts' => $partList,
            'placement' => $placement,
            'created_at' => gmdate('c'),
        ];
        $this->putManifest($bucket, $key, $manifest);

        // 清掉暫存的 upload
        unset($uploads[$uploadId]);
        $this->writeUploads($uploads);

        return $manifest;
    }

    /**
     * 依策略把物件位元組放到節點。
     *   REPLICA：整顆物件複製 R 份，放到一致性雜湊選出的 R 個節點。
     *   EC：抹除碼切成 k+m 個分片，每個分片各放到一個節點。
     *
     * @return array 放置資訊（供還原時定位 block）
     */
    private function placeAndStore(string $objId, string $object, string $strategy, int $replicas): array
    {
        if ($strategy === 'EC') {
            $enc = Erasure::encode($object);
            $shards = $enc['shards'];               // 0..k+m-1
            $shardCount = count($shards);
            $nodesForShards = $this->placeNodes($objId . ':ec', $shardCount);
            $blocks = [];
            foreach ($shards as $i => $bytes) {
                $node = $nodesForShards[$i % count($nodesForShards)];
                $blockId = $objId . '_s' . $i;
                $this->writeBlock($node, $blockId, $bytes);
                $blocks[] = [
                    'shard' => $i,
                    'role' => $i < Erasure::K ? 'data' : 'parity',
                    'node' => $node,
                    'blockId' => $blockId,
                    'len' => strlen($bytes),
                ];
            }
            return [
                'mode' => 'EC',
                'k' => Erasure::K,
                'm' => Erasure::M,
                'shardLen' => $enc['shardLen'],
                'blocks' => $blocks,
            ];
        }

        // REPLICA
        $nodesForReplicas = $this->placeNodes($objId . ':rep', $replicas);
        $blocks = [];
        foreach ($nodesForReplicas as $i => $node) {
            $blockId = $objId . '_r' . $i;
            $this->writeBlock($node, $blockId, $object);
            $blocks[] = ['replica' => $i, 'node' => $node, 'blockId' => $blockId, 'len' => strlen($object)];
        }
        return ['mode' => 'REPLICA', 'replicas' => $replicas, 'blocks' => $blocks];
    }

    // ============ 下載 / 還原 ============

    /**
     * 依 manifest 從節點讀回物件；遇到失效節點時用副本或 parity 還原。
     *
     * @return array{bytes:string, recovered:bool, detail:array}
     */
    public function fetchObject(array $manifest): array
    {
        $p = $manifest['placement'];
        $size = (int) $manifest['size'];
        $recovered = false;
        $detail = [];

        if (($p['mode'] ?? '') === 'EC') {
            $total = (int) $p['k'] + (int) $p['m'];
            $shards = [];
            for ($i = 0; $i < $total; $i++) {
                $shards[$i] = null;
            }
            foreach ($p['blocks'] as $b) {
                $bytes = $this->readBlock((string) $b['node'], (string) $b['blockId']);
                $shards[(int) $b['shard']] = $bytes;
                $detail[] = [
                    'shard' => $b['shard'],
                    'role' => $b['role'],
                    'node' => $b['node'],
                    'status' => $bytes === null ? 'MISSING（節點失效）' : 'ok',
                ];
                if ($bytes === null) {
                    $recovered = true;
                }
            }
            $bytes = Erasure::decode($shards, $size);
            return ['bytes' => $bytes, 'recovered' => $recovered, 'detail' => $detail];
        }

        // REPLICA：取第一個可讀的副本
        $bytes = null;
        $usedReplica = null;
        foreach ($p['blocks'] as $b) {
            $candidate = $this->readBlock((string) $b['node'], (string) $b['blockId']);
            $detail[] = [
                'replica' => $b['replica'],
                'node' => $b['node'],
                'status' => $candidate === null ? 'MISSING（節點失效）' : 'ok',
            ];
            if ($candidate !== null && $bytes === null) {
                $bytes = $candidate;
                $usedReplica = $b['replica'];
            } elseif ($candidate === null) {
                $recovered = true; // 有副本掛了但仍從其他副本讀到 → 算「容錯成功」
            }
        }
        if ($bytes === null) {
            throw new RuntimeException('所有副本都失效，無法讀取');
        }
        $detail[] = ['note' => '採用副本 #' . $usedReplica];
        return ['bytes' => substr($bytes, 0, $size), 'recovered' => $recovered, 'detail' => $detail];
    }

    // ============ Metadata 服務 ============

    private function metaFile(): string
    {
        return $this->dataDir . '/manifests.json';
    }

    private function readManifests(): array
    {
        $f = $this->metaFile();
        if (!file_exists($f)) {
            return [];
        }
        $a = json_decode((string) file_get_contents($f), true);
        return is_array($a) ? $a : [];
    }

    private function putManifest(string $bucket, string $key, array $manifest): void
    {
        // 強一致 metadata：用 flock 序列化讀-改-寫
        $f = $this->metaFile();
        $fp = fopen($f, 'c+');
        if ($fp === false) {
            throw new RuntimeException('無法開啟 metadata');
        }
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $all = $raw !== '' && $raw !== false ? (json_decode($raw, true) ?: []) : [];
        $all[$bucket . '/' . $key] = $manifest;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function getManifest(string $bucket, string $key): ?array
    {
        $all = $this->readManifests();
        return $all[$bucket . '/' . $key] ?? null;
    }

    /** @return list<array> 所有物件 manifest（給測試頁列表用） */
    public function listObjects(): array
    {
        return array_values($this->readManifests());
    }

    // ============ Bucket ============

    private function bucketFile(): string
    {
        return $this->dataDir . '/buckets.json';
    }

    public function buckets(): array
    {
        $f = $this->bucketFile();
        if (!file_exists($f)) {
            return [];
        }
        $a = json_decode((string) file_get_contents($f), true);
        return is_array($a) ? array_values($a) : [];
    }

    public function createBucket(string $bucket): array
    {
        $b = $this->buckets();
        if (!in_array($bucket, $b, true)) {
            $b[] = $bucket;
            file_put_contents($this->bucketFile(), json_encode(array_values($b), JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return $b;
    }

    // ============ 預簽 URL ============

    /**
     * 產生預簽 URL 的簽章字串：hash_hmac 簽 method+bucket+key+expires。
     * 回傳 query 參數（expires 與 sig）。
     */
    public function presign(string $method, string $bucket, string $key, int $ttlSeconds): array
    {
        $expires = time() + $ttlSeconds;
        $sig = $this->signFor($method, $bucket, $key, $expires);
        return [
            'method' => $method,
            'bucket' => $bucket,
            'key' => $key,
            'expires' => $expires,
            'sig' => $sig,
            'url' => '/api/object?bucket=' . rawurlencode($bucket)
                . '&key=' . rawurlencode($key)
                . '&expires=' . $expires
                . '&sig=' . $sig,
        ];
    }

    /**
     * 驗證預簽：簽章相符（hash_equals 防時序攻擊）且未過期。
     *
     * @return array{ok:bool, reason:string}
     */
    public function verifyPresign(string $method, string $bucket, string $key, int $expires, string $sig): array
    {
        $expected = $this->signFor($method, $bucket, $key, $expires);
        if (!hash_equals($expected, $sig)) {
            return ['ok' => false, 'reason' => '簽章不符（URL 被竄改或金鑰不對）'];
        }
        if (time() > $expires) {
            return ['ok' => false, 'reason' => '已過期（expires=' . $expires . '，now=' . time() . '）'];
        }
        return ['ok' => true, 'reason' => '簽章正確且未過期'];
    }

    private function signFor(string $method, string $bucket, string $key, int $expires): string
    {
        $payload = strtoupper($method) . "\n" . $bucket . "\n" . $key . "\n" . $expires;
        return hash_hmac('sha256', $payload, $this->signingKey);
    }

    // ============ 整體狀態（測試頁/除錯用） ============

    public function state(): array
    {
        $nodeBlocks = [];
        foreach ($this->nodes as $n) {
            $files = glob($this->nodesDir . '/' . $n . '/*.blk') ?: [];
            $nodeBlocks[$n] = [
                'up' => $this->isNodeUp($n),
                'blocks' => count($files),
            ];
        }
        return [
            'nodes' => $nodeBlocks,
            'failedNodes' => $this->failedNodes(),
            'buckets' => $this->buckets(),
            'objectCount' => count($this->readManifests()),
        ];
    }
}
