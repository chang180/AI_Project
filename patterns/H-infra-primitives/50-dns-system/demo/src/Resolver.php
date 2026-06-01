<?php
declare(strict_types=1);

/**
 * DNS 系統 Demo — 核心解析器
 * --------------------------------------------------
 * 真實系統：stub resolver（作業系統）→ recursive resolver（如 8.8.8.8）
 *           → root（13 組 anycast）→ TLD（.com 的權威）→ authoritative
 *           （該網域自己的權威伺服器）三層遞迴查找；resolver 有快取（TTL）。
 *
 * 本 demo：用記憶體 + JSON 檔模擬 root / TLD / authoritative「三張區域表」，
 *          忠實重現：
 *            1) 階層遞迴解析（root→TLD→authoritative，回傳完整解析鏈）
 *            2) 解析快取（含 TTL，命中時省去整段遞迴）
 *            3) 記錄型別 A / CNAME（CNAME 要再跟一次直到 A）
 *            4) GeoDNS（依 client 區域回最近的 IP）
 *          這些「DNS 邏輯」都是真實可執行的；只有網路傳輸被記憶體查表取代。
 *
 * 狀態檔（demo/data/）：
 *   zones.json   — 權威區域資料（每個網域的 A / CNAME / NS 記錄）
 *   cache.json   — recursive resolver 的快取（name|type → {answer, expire_at}）
 *
 * 全程用 flock(LOCK_EX) 把「讀-改-寫」鎖成原子操作，模擬真實 resolver
 * 多執行緒共用一份快取的併發安全。
 */
final class Resolver
{
    private string $zonesFile;
    private string $cacheFile;

    /** root 伺服器知道每個 TLD 的權威在哪（這裡只是示意名稱） */
    private const ROOT_HINTS = [
        'com' => 'a.gtld-servers.net',
        'net' => 'a.gtld-servers.net',
        'org' => 'a.org-servers.net',
        'io'  => 'a.nic.io',
    ];

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->zonesFile = $dataDir . '/zones.json';
        $this->cacheFile = $dataDir . '/cache.json';

        if (!file_exists($this->zonesFile)) {
            $this->writeJson($this->zonesFile, $this->seedZones());
        }
        if (!file_exists($this->cacheFile)) {
            $this->writeJson($this->cacheFile, []);
        }
    }

    // ===================================================================
    //  核心：解析一個網域名
    // ===================================================================
    /**
     * 解析 $name，回傳完整結果（含解析鏈與是否命中快取）。
     *
     * @param string $name          要查的網域，如 www.example.com
     * @param string $clientRegion  client 所在區域（GeoDNS 用），如 'tw' / 'us' / 'eu'
     * @return array{
     *   name:string, type:string, answer:?string, ttl:int,
     *   cache_hit:bool, client_region:string,
     *   chain:array<int,array{step:string,detail:string}>,
     *   geo:bool
     * }
     */
    public function resolve(string $name, string $clientRegion = 'tw'): array
    {
        $name = strtolower(trim($name, " \t\n\r.\0\x0B"));
        $chain = [];

        // ---- 1) 先查快取（recursive resolver 的快取，含 TTL）----
        // 快取鍵帶上區域：GeoDNS 答案依區域不同，真實系統靠 EDNS Client Subnet 分區快取。
        $cached = $this->cacheGet($name, $clientRegion);
        if ($cached !== null) {
            $chain[] = [
                'step'   => '快取命中 (cache hit)',
                'detail' => "在 recursive resolver 快取找到 {$name} → {$cached['answer']}（剩餘 TTL {$cached['ttl_left']}s）→ 省去整段遞迴",
            ];
            return [
                'name'          => $name,
                'type'          => $cached['type'],
                'answer'        => $cached['answer'],
                'ttl'           => $cached['ttl_left'],
                'cache_hit'     => true,
                'client_region' => $clientRegion,
                'chain'         => $chain,
                'geo'           => $cached['geo'] ?? false,
            ];
        }

        // ---- 2) 快取沒有 → 開始階層遞迴解析 ----
        $chain[] = [
            'step'   => 'stub → recursive resolver',
            'detail' => "作業系統把 {$name} 交給 recursive resolver；快取未命中，開始遞迴查找",
        ];

        $result = $this->recursiveResolve($name, $clientRegion, $chain, 0);

        // ---- 3) 解析成功才寫入快取（依該記錄的 TTL）----
        if ($result['answer'] !== null) {
            $this->cachePut($name, $clientRegion, $result['type'], $result['answer'], $result['ttl'], $result['geo']);
        }

        return [
            'name'          => $name,
            'type'          => $result['type'],
            'answer'        => $result['answer'],
            'ttl'           => $result['ttl'],
            'cache_hit'     => false,
            'client_region' => $clientRegion,
            'chain'         => $chain,
            'geo'           => $result['geo'],
        ];
    }

    /**
     * 階層遞迴解析的「真正幹活」函式：root → TLD → authoritative。
     * CNAME 會在這裡「再跟一次」（最多 $depth 次，防無限迴圈）。
     *
     * @return array{type:string, answer:?string, ttl:int, geo:bool}
     */
    private function recursiveResolve(string $name, string $clientRegion, array &$chain, int $depth): array
    {
        if ($depth > 8) {
            $chain[] = ['step' => '錯誤', 'detail' => 'CNAME 跟太多層（疑似迴圈），中止'];
            return ['type' => 'NONE', 'answer' => null, 'ttl' => 0, 'geo' => false];
        }

        $tld = $this->tldOf($name);

        // ---- root 層：問「.{tld} 的權威在哪？」----
        $chain[] = [
            'step'   => 'root 伺服器',
            'detail' => $depth === 0
                ? "問 root：「.{$tld} 的權威伺服器在哪？」root 回：去找 " . (self::ROOT_HINTS[$tld] ?? "（未知 TLD .{$tld}）")
                : "（跟隨 CNAME，第 " . ($depth + 1) . " 次）再問 root：.{$tld} 的權威在哪",
        ];

        if (!isset(self::ROOT_HINTS[$tld])) {
            $chain[] = ['step' => 'NXDOMAIN', 'detail' => "root 不認得 TLD .{$tld} → 查無此名（負面快取可記住一段時間）"];
            return ['type' => 'NXDOMAIN', 'answer' => null, 'ttl' => 60, 'geo' => false];
        }

        // ---- TLD 層：問「{name} 這個網域的權威在哪？」----
        $zones = $this->readJson($this->zonesFile);
        $apex = $this->apexOf($name); // 如 example.com
        $chain[] = [
            'step'   => 'TLD 伺服器 (.' . $tld . ')',
            'detail' => "問 .{$tld} 的權威：「{$apex} 的權威伺服器（NS）在哪？」",
        ];

        if (!isset($zones[$apex])) {
            $chain[] = ['step' => 'NXDOMAIN', 'detail' => "TLD 沒有 {$apex} 的委派 → 查無此名"];
            return ['type' => 'NXDOMAIN', 'answer' => null, 'ttl' => 60, 'geo' => false];
        }

        $ns = $zones[$apex]['ns'] ?? ('ns1.' . $apex);
        $chain[] = [
            'step'   => 'TLD 回授權 (NS)',
            'detail' => ".{$tld} 回：{$apex} 的權威伺服器是 {$ns}",
        ];

        // ---- authoritative 層：問「{name} 的記錄是什麼？」----
        $records = $zones[$apex]['records'] ?? [];
        if (!isset($records[$name])) {
            $chain[] = ['step' => 'NXDOMAIN', 'detail' => "權威 {$ns} 沒有 {$name} 的記錄 → 查無此名"];
            return ['type' => 'NXDOMAIN', 'answer' => null, 'ttl' => 60, 'geo' => false];
        }

        $rec = $records[$name];
        $type = (string) $rec['type'];
        $ttl  = (int) ($rec['ttl'] ?? 300);

        // ---- CNAME：要「再跟一次」直到拿到 A ----
        if ($type === 'CNAME') {
            $target = (string) $rec['value'];
            $chain[] = [
                'step'   => "權威回 CNAME",
                'detail' => "權威 {$ns} 回：{$name} 是 CNAME → {$target}（別名）；需要再解析 {$target}",
            ];
            // 遞迴跟隨別名
            return $this->recursiveResolve($target, $clientRegion, $chain, $depth + 1);
        }

        // ---- A：可能是 GeoDNS（依區域回不同 IP）----
        if ($type === 'A') {
            // GeoDNS：value 若是 array（依區域），就挑最近的；否則單一 IP
            if (isset($rec['geo']) && is_array($rec['geo'])) {
                $geoMap = $rec['geo'];
                $ip = $geoMap[$clientRegion] ?? $geoMap['default'] ?? reset($geoMap);
                $chain[] = [
                    'step'   => '權威回 A (GeoDNS)',
                    'detail' => "權威 {$ns} 依 client 區域『{$clientRegion}』回最近的 IP：{$ip}（其他區域會回不同 IP）",
                ];
                return ['type' => 'A', 'answer' => $ip, 'ttl' => $ttl, 'geo' => true];
            }

            $ip = (string) $rec['value'];
            $chain[] = [
                'step'   => '權威回 A',
                'detail' => "權威 {$ns} 回：{$name} 的 A 記錄 = {$ip}",
            ];
            return ['type' => 'A', 'answer' => $ip, 'ttl' => $ttl, 'geo' => false];
        }

        $chain[] = ['step' => '未支援型別', 'detail' => "記錄型別 {$type} 在本 demo 未實作"];
        return ['type' => $type, 'answer' => null, 'ttl' => 0, 'geo' => false];
    }

    // ===================================================================
    //  新增 / 列出記錄
    // ===================================================================
    /**
     * 新增或更新一筆權威記錄。
     * @param array $geo  GeoDNS 用：['tw'=>'1.1.1.1','us'=>'2.2.2.2','default'=>'...']
     */
    public function addRecord(string $name, string $type, string $value, int $ttl = 300, array $geo = []): array
    {
        $name = strtolower(trim($name, " \t\n\r.\0\x0B"));
        $type = strtoupper(trim($type));
        if (!in_array($type, ['A', 'CNAME'], true)) {
            return ['ok' => false, 'error' => '只支援 A / CNAME'];
        }
        $apex = $this->apexOf($name);

        $fp = fopen($this->zonesFile, 'c+');
        flock($fp, LOCK_EX);
        $zones = json_decode((string) stream_get_contents($fp), true) ?: [];

        if (!isset($zones[$apex])) {
            $zones[$apex] = ['ns' => 'ns1.' . $apex, 'records' => []];
        }
        $rec = ['type' => $type, 'ttl' => max(1, $ttl)];
        if ($type === 'A' && !empty($geo)) {
            $rec['geo'] = $geo;
            $rec['value'] = $geo['default'] ?? reset($geo);
        } else {
            $rec['value'] = strtolower(trim($value, " \t\n\r.\0\x0B"));
        }
        $zones[$apex]['records'][$name] = $rec;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($zones, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        // 新增/改記錄 → 失效相關快取（讓變更生效）
        $this->cacheInvalidate($name);

        return ['ok' => true, 'name' => $name, 'record' => $rec];
    }

    /** 回傳目前所有區域 + 快取狀態（給測試頁顯示） */
    public function state(): array
    {
        $zones = $this->readJson($this->zonesFile);
        $cacheRaw = $this->readJson($this->cacheFile);
        $now = time();
        $cache = [];
        foreach ($cacheRaw as $k => $entry) {
            $left = (int) $entry['expire_at'] - $now;
            $cache[$k] = [
                'answer'   => $entry['answer'],
                'type'     => $entry['type'],
                'ttl_left' => $left,
                'expired'  => $left <= 0,
            ];
        }
        return ['zones' => $zones, 'cache' => $cache];
    }

    /** 清空快取（示範「快取清掉後又要走完整遞迴」） */
    public function flushCache(): void
    {
        $this->writeJson($this->cacheFile, []);
    }

    // ===================================================================
    //  快取（含 TTL）
    // ===================================================================
    /** @return array{type:string,answer:string,ttl_left:int,geo:bool}|null */
    private function cacheGet(string $name, string $region): ?array
    {
        $fp = fopen($this->cacheFile, 'c+');
        flock($fp, LOCK_EX);
        $cache = json_decode((string) stream_get_contents($fp), true) ?: [];
        $now = time();
        // 先找該區域專屬鍵（GeoDNS），找不到再找全區共用鍵（非 Geo 記錄）
        $key = isset($cache[$this->cacheKey($name, $region)])
            ? $this->cacheKey($name, $region)
            : $this->cacheKey($name, '*');

        $hit = null;
        if (isset($cache[$key])) {
            $entry = $cache[$key];
            if ((int) $entry['expire_at'] > $now) {
                $hit = [
                    'type'     => (string) $entry['type'],
                    'answer'   => (string) $entry['answer'],
                    'ttl_left' => (int) $entry['expire_at'] - $now,
                    'geo'      => (bool) ($entry['geo'] ?? false),
                ];
            } else {
                // TTL 過期 → 移除（讓下次重新遞迴）
                unset($cache[$key]);
                $this->rewriteOpen($fp, $cache);
            }
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        return $hit;
    }

    private function cachePut(string $name, string $region, string $type, string $answer, int $ttl, bool $geo): void
    {
        $fp = fopen($this->cacheFile, 'c+');
        flock($fp, LOCK_EX);
        $cache = json_decode((string) stream_get_contents($fp), true) ?: [];
        // GeoDNS 答案依區域不同 → 快取鍵帶區域（模擬 EDNS Client Subnet 分區快取）；
        // 非 Geo 記錄全區共用同一份（key 帶 '*'）。
        $cache[$this->cacheKey($name, $geo ? $region : '*')] = [
            'type'      => $type,
            'answer'    => $answer,
            'geo'       => $geo,
            'expire_at' => time() + max(1, $ttl),
        ];
        $this->rewriteOpen($fp, $cache);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /** 失效某網域所有區域的快取（改記錄後讓變更生效） */
    private function cacheInvalidate(string $name): void
    {
        $fp = fopen($this->cacheFile, 'c+');
        flock($fp, LOCK_EX);
        $cache = json_decode((string) stream_get_contents($fp), true) ?: [];
        $prefix = $name . '|';
        foreach (array_keys($cache) as $k) {
            if (str_starts_with($k, $prefix)) {
                unset($cache[$k]);
            }
        }
        $this->rewriteOpen($fp, $cache);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function cacheKey(string $name, string $region): string
    {
        return $name . '|' . $region;
    }

    // ===================================================================
    //  小工具
    // ===================================================================
    private function tldOf(string $name): string
    {
        $parts = explode('.', $name);
        return $parts[count($parts) - 1] ?? '';
    }

    /** 取得 apex（註冊網域），如 www.example.com → example.com */
    private function apexOf(string $name): string
    {
        $parts = explode('.', $name);
        $n = count($parts);
        if ($n <= 2) {
            return $name;
        }
        return $parts[$n - 2] . '.' . $parts[$n - 1];
    }

    private function readJson(string $file): array
    {
        $raw = (string) file_get_contents($file);
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    private function writeJson(string $file, array $data): void
    {
        file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    /** 在已 flock 的 fp 上覆寫 JSON */
    private function rewriteOpen($fp, array $data): void
    {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
    }

    /** 預載示範區域：example.com（含 GeoDNS）、blog→CNAME、io 網域 */
    private function seedZones(): array
    {
        return [
            'example.com' => [
                'ns' => 'ns1.example.com',
                'records' => [
                    'example.com' => ['type' => 'A', 'ttl' => 300, 'value' => '93.184.216.34'],
                    'www.example.com' => [
                        'type' => 'A',
                        'ttl'  => 60,
                        'value' => '203.0.113.10',
                        'geo'  => [
                            'tw'      => '203.0.113.10',  // 台灣 → 亞太節點
                            'us'      => '198.51.100.20', // 美國 → 北美節點
                            'eu'      => '192.0.2.30',    // 歐洲 → 歐洲節點
                            'default' => '203.0.113.10',
                        ],
                    ],
                    'blog.example.com' => ['type' => 'CNAME', 'ttl' => 300, 'value' => 'www.example.com'],
                ],
            ],
            'acme.io' => [
                'ns' => 'ns1.acme.io',
                'records' => [
                    'acme.io'     => ['type' => 'A', 'ttl' => 120, 'value' => '198.51.100.77'],
                    'api.acme.io' => ['type' => 'A', 'ttl' => 120, 'value' => '198.51.100.78'],
                ],
            ],
        ];
    }
}
