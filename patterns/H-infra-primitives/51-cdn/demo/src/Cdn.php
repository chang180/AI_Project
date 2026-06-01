<?php
declare(strict_types=1);

require_once __DIR__ . '/HashRing.php';
require_once __DIR__ . '/Origin.php';

/**
 * CDN 核心：邊緣節點快取 + 就近路由 + 回源 + TTL + purge + 命中率統計
 * ==================================================================
 * 真實 CDN：客戶端 → DNS/anycast 導到「就近」的邊緣 PoP → 邊緣查快取
 *   命中(hit) → 直接回；未命中(miss) → 回源 origin 抓 → 依 Cache-Control TTL 存到邊緣 → 回。
 *
 * 本 demo 的對應：
 *   - 邊緣節點 = 各區域 PoP（如 tw / jp / us / eu），用記憶體模擬其快取狀態（存 JSON 檔）。
 *   - 就近路由 = 依 client 的 region 直接選對應的邊緣節點（真實世界靠 anycast/GeoDNS）。
 *   - 一致性雜湊 = 在「同一區域內若有多台邊緣機器」時，用來決定資產落在哪一台
 *                  （本 demo 每區一個邏輯節點；雜湊環示範資產 → 節點的穩定對應）。
 *   - TTL = origin 回應的 Cache-Control max-age；存進邊緣時記下 expires_at，過期即視為 miss。
 *   - purge = 主動失效：刪掉「所有」邊緣節點上某資產的快取，下次請求必 miss 回源。
 *
 * 狀態檔（edges.json）結構：
 *   { "<region>": { "<asset>": { "body": ..., "expires_at": <epoch> } }, ... }
 *   全程用 flock(LOCK_EX) 做原子讀-改-寫，避免多請求並發踩踏（模擬分散式快取的原子操作）。
 *
 * 統計檔（stats.json）：每區域累計 hit / miss，用來算命中率與回源比。
 */
final class Cdn
{
    /** 區域 → 邊緣節點名（每區一個邏輯 PoP） */
    private const REGIONS = ['tw', 'jp', 'us', 'eu'];

    private string $edgesFile;
    private string $statsFile;
    private HashRing $ring;
    private Origin $origin;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->edgesFile = $dataDir . '/edges.json';
        $this->statsFile = $dataDir . '/stats.json';
        $this->origin = new Origin($dataDir);
        // 每個區域當成環上的一個實體節點；一致性雜湊把資產穩定對應到節點。
        $this->ring = new HashRing(self::REGIONS);

        if (!file_exists($this->edgesFile)) {
            $this->writeJson($this->edgesFile, array_fill_keys(self::REGIONS, []));
        }
        if (!file_exists($this->statsFile)) {
            $this->writeJson($this->statsFile, array_fill_keys(
                self::REGIONS,
                ['hit' => 0, 'miss' => 0]
            ));
        }
    }

    public static function regions(): array
    {
        return self::REGIONS;
    }

    public function origin(): Origin
    {
        return $this->origin;
    }

    /**
     * 從某 region 請求某 asset，回傳結果與是否命中。
     * 這是 CDN 的關鍵路徑：
     *   1. 就近選邊緣節點（依 region）
     *   2. 查該邊緣快取：命中且未過期 → hit
     *   3. 否則 miss → 回源 origin 抓 → 依 TTL 存進邊緣 → 回
     */
    public function fetch(string $asset, string $region): array
    {
        if (!in_array($region, self::REGIONS, true)) {
            $region = self::REGIONS[0]; // 不認得的區域 → 退回預設（真實世界由 anycast 決定）
        }

        // 一致性雜湊：示範資產 → 節點的穩定對應（本 demo 仍以 region 就近為主）。
        $ringNode = $this->ring->getNode($asset);

        $fp = fopen($this->edgesFile, 'c+');
        flock($fp, LOCK_EX);
        $edges = json_decode((string) (fread($fp, 1 << 22) ?: '{}'), true) ?: [];

        $now = time();
        $entry = $edges[$region][$asset] ?? null;
        $hit = false;
        $body = null;
        $ttl = null;
        $served = 'edge'; // edge | origin

        if ($entry !== null && ($entry['expires_at'] ?? 0) > $now) {
            // ---- 邊緣命中 (HIT) ----
            $hit = true;
            $body = $entry['body'];
            $served = 'edge';
        } else {
            // ---- 邊緣未命中 (MISS) → 回源 ----
            $fromOrigin = $this->origin->fetch($asset);
            if ($fromOrigin === null) {
                flock($fp, LOCK_UN);
                fclose($fp);
                $this->bumpStat($region, 'miss');
                return [
                    'found'   => false,
                    'hit'     => false,
                    'region'  => $region,
                    'node'    => $ringNode,
                    'served'  => 'origin',
                    'asset'   => $asset,
                ];
            }
            $body = $fromOrigin['body'];
            $ttl  = $fromOrigin['ttl'];
            // 依 Cache-Control TTL 存進邊緣
            $edges[$region][$asset] = [
                'body'       => $body,
                'expires_at' => $now + $ttl,
            ];
            $this->writeFp($fp, $edges);
            $served = 'origin';
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        $this->bumpStat($region, $hit ? 'hit' : 'miss');

        return [
            'found'  => true,
            'hit'    => $hit,
            'region' => $region,
            'node'   => $ringNode,
            'served' => $served,
            'asset'  => $asset,
            'body'   => $body,
            'ttl'    => $ttl,
        ];
    }

    /**
     * 快取失效（purge）：刪掉所有邊緣節點上該資產的快取。
     * 下一次任何區域請求它都必然 miss → 回源拿最新版本。
     * 回傳實際清掉的（區域）筆數。
     */
    public function purge(string $asset): int
    {
        $fp = fopen($this->edgesFile, 'c+');
        flock($fp, LOCK_EX);
        $edges = json_decode((string) (fread($fp, 1 << 22) ?: '{}'), true) ?: [];

        $removed = 0;
        foreach ($edges as $region => $assets) {
            if (isset($edges[$region][$asset])) {
                unset($edges[$region][$asset]);
                $removed++;
            }
        }
        $this->writeFp($fp, $edges);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $removed;
    }

    /** 命中率統計：各區 hit/miss、總命中率、回源比、回源總次數 */
    public function stats(): array
    {
        $perRegion = $this->readJson($this->statsFile);
        $totalHit = 0;
        $totalMiss = 0;
        $out = [];
        foreach ($perRegion as $region => $s) {
            $h = (int) ($s['hit'] ?? 0);
            $m = (int) ($s['miss'] ?? 0);
            $req = $h + $m;
            $totalHit += $h;
            $totalMiss += $m;
            $out[$region] = [
                'hit'      => $h,
                'miss'     => $m,
                'requests' => $req,
                'hit_rate' => $req > 0 ? round($h / $req, 4) : 0.0,
            ];
        }
        $total = $totalHit + $totalMiss;
        return [
            'per_region'    => $out,
            'total_hit'     => $totalHit,
            'total_miss'    => $totalMiss,
            'total_request' => $total,
            'hit_rate'      => $total > 0 ? round($totalHit / $total, 4) : 0.0,
            // 回源比 = miss 數 / 總請求數（越低越省頻寬）
            'origin_ratio'  => $total > 0 ? round($totalMiss / $total, 4) : 0.0,
            'origin_fetches' => $this->origin->fetchCount(),
        ];
    }

    /** 目前各邊緣節點上有快取哪些資產（含剩餘 TTL 秒），供測試頁顯示 */
    public function edgeState(): array
    {
        $edges = $this->readJson($this->edgesFile);
        $now = time();
        $out = [];
        foreach ($edges as $region => $assets) {
            $out[$region] = [];
            foreach ($assets as $asset => $entry) {
                $remain = (int) (($entry['expires_at'] ?? 0) - $now);
                $out[$region][$asset] = max(0, $remain); // 剩餘 TTL 秒
            }
        }
        return $out;
    }

    // ===================== 內部工具 =====================

    private function bumpStat(string $region, string $kind): void
    {
        $fp = fopen($this->statsFile, 'c+');
        flock($fp, LOCK_EX);
        $stats = json_decode((string) (fread($fp, 1 << 20) ?: '{}'), true) ?: [];
        $stats[$region][$kind] = (int) ($stats[$region][$kind] ?? 0) + 1;
        $this->writeFp($fp, $stats);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function writeFp($fp, array $data): void
    {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
    }

    private function readJson(string $file): array
    {
        $json = (string) file_get_contents($file);
        return json_decode($json, true) ?: [];
    }

    private function writeJson(string $file, array $data): void
    {
        file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
