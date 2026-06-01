<?php
declare(strict_types=1);

require_once __DIR__ . '/BloomFilter.php';

/**
 * 網頁爬蟲核心（教學版，單 process 內用「內建假網路圖」做確定性爬取）
 * =====================================================================
 * 誠實聲明：本 demo **不連真網路**。process 內放一個小型假網路圖
 * （url → { 內容, 外連 links }），讓爬蟲可確定性地 BFS 爬取，方便觀察與測試。
 *
 * 但以下爬蟲核心邏輯皆為真實且正確：
 *   1. BFS frontier（待爬佇列）：每步從 frontier 取出一個 url、抓內容、抽出外連 links、
 *      把「沒見過的」link 加回 frontier 尾端 → 廣度優先擴展。
 *   2. URL 去重：bloom filter 先擋（省記憶體），mightContain 為真時再查精確 seen 集合
 *      二次確認（避免偽陽性漏爬）。沒見過才入 frontier。
 *   3. Politeness（禮貌性）：每個 domain 記「上次抓取的邏輯時間」，同 domain 兩次抓取
 *      必須間隔 >= crawlDelay（crawl-delay）。太密 → 該 url 被延後（推回 frontier 尾端，
 *      標記為 deferred），避免對單一站台造成壓力。
 *   4. 分散式 frontier：依 domain 雜湊把 url 分到 N 個 shard（同 domain → 同 shard），
 *      確保同站的 politeness 狀態集中在同一 shard，不會被多台機器同時猛打一個站。
 *   5. 重抓排程：已抓頁面記 fetchedAt + nextRecrawlAt（依 freshness 簡化排程），
 *      到期可重新放回 frontier 重抓。
 *   6. 爬蟲陷阱防護：限制最大深度（避免無限 URL / 日曆型無限連結把 frontier 撐爆）。
 *
 * 狀態持久化：整個爬取狀態存成單一 JSON 檔（data/crawler.json），用 flock 保護，
 * 跨 HTTP 請求保存 frontier / visited / bloom / per-domain 狀態。
 */
final class Crawler
{
    private string $dataDir;
    private string $stateFile;

    /** shard 數量（分散式 frontier 分片數）。 */
    private const SHARD_COUNT = 3;
    /** 每個 domain 的 crawl-delay（邏輯時間單位）。 */
    private const CRAWL_DELAY = 2;
    /** 最大爬取深度（防爬蟲陷阱 / 無限 URL）。 */
    private const MAX_DEPTH = 6;
    /** 已抓頁面多久後可重抓（邏輯時間，簡化的 freshness 排程）。 */
    private const RECRAWL_INTERVAL = 20;
    /** bloom filter 參數：位元數 / 雜湊數。 */
    private const BLOOM_M = 2048;
    private const BLOOM_K = 4;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dataDir = rtrim($dataDir, "/\\");
        $this->stateFile = $this->dataDir . '/crawler.json';
        if (!file_exists($this->stateFile)) {
            $this->writeState($this->freshState());
        }
    }

    // ============================================================
    // 對外 API（給路由呼叫）
    // ============================================================

    /**
     * 載入範例假網路並以給定 seeds 重置爬取狀態。
     * @param string[] $seeds 種子 URL（沒給就用範例網路全部入口）
     */
    public function seed(array $seeds = []): array
    {
        $state = $this->freshState();
        $web = $this->sampleWeb();
        if ($seeds === []) {
            $seeds = ['http://news.example/home'];
        }
        foreach ($seeds as $url) {
            $url = trim($url);
            if ($url === '' || !isset($web[$url])) {
                continue;
            }
            $this->enqueue($state, $url, 0);
        }
        $this->writeState($state);
        return $this->publicState($state);
    }

    /**
     * 步進 N 步：每步從 frontier 取一個 url 處理。
     * 回傳每一步發生的事件（fetched / deferred / dedup-skip / trap-skip / frontier-empty）。
     */
    public function crawl(int $steps = 1): array
    {
        $steps = max(1, min(50, $steps));
        $state = $this->readState();
        $events = [];
        for ($i = 0; $i < $steps; $i++) {
            $ev = $this->stepOnce($state);
            $events[] = $ev;
            if ($ev['type'] === 'frontier-empty') {
                break;
            }
        }
        $this->writeState($state);
        return ['steps' => count($events), 'events' => $events, 'state' => $this->publicState($state)];
    }

    public function getState(): array
    {
        return $this->publicState($this->readState());
    }

    public function reset(): array
    {
        $state = $this->freshState();
        $this->writeState($state);
        return $this->publicState($state);
    }

    // ============================================================
    // 單步爬取（核心）
    // ============================================================

    private function stepOnce(array &$state): array
    {
        $clock = ++$state['clock'];

        // 1) 先嘗試把「到期可重抓」的頁面放回 frontier（freshness 排程）。
        $this->scheduleRecrawls($state, $clock);

        // 2) 從某個 shard 的 frontier 取出下一個可處理的 url（輪詢 shard）。
        $picked = $this->dequeue($state, $clock);
        if ($picked === null) {
            // frontier 空了。若仍有頁面排了未來重抓 → 視為 idle（時鐘繼續走，等重抓到期）；
            // 若連重抓都沒有 → 真正爬完。
            $nextDue = $this->nextRecrawlDue($state);
            if ($nextDue !== null) {
                return [
                    'type' => 'idle',
                    'clock' => $clock,
                    'msg' => "frontier 暫空，等待下一次重抓（最近一筆於 t={$nextDue} 到期）",
                ];
            }
            return ['type' => 'frontier-empty', 'clock' => $clock, 'msg' => 'frontier 已空且無待重抓，爬取完成'];
        }
        [$url, $depth, $shard, $deferred] = [$picked['url'], $picked['depth'], $picked['shard'], $picked['deferred']];

        // 3) Politeness：同 domain crawl-delay 檢查。太密 → 延後（推回 frontier 尾端）。
        $domain = $this->domainOf($url);
        $last = $state['domains'][$domain]['lastFetch'] ?? null;
        if ($last !== null && ($clock - $last) < self::CRAWL_DELAY) {
            $this->enqueue($state, $url, $depth, true); // 標記 deferred 重新入列
            return [
                'type' => 'deferred',
                'clock' => $clock,
                'url' => $url,
                'domain' => $domain,
                'shard' => $shard,
                'msg' => "politeness：domain {$domain} 距上次抓取僅 " . ($clock - $last)
                    . " < crawl-delay " . self::CRAWL_DELAY . "，延後重排",
            ];
        }

        // 4) 抓取（從假網路圖取內容 + 外連 links）。
        $web = $this->sampleWeb();
        $page = $web[$url] ?? ['content' => '(無內容)', 'links' => []];
        $state['domains'][$domain]['lastFetch'] = $clock;
        $state['visited'][$url] = [
            'depth' => $depth,
            'fetchedAt' => $clock,
            'nextRecrawlAt' => $clock + self::RECRAWL_INTERVAL,
            'fetchCount' => ($state['visited'][$url]['fetchCount'] ?? 0) + 1,
            'outLinks' => count($page['links']),
        ];

        // 5) 抽出 links、去重後把新 url 加回 frontier。
        $added = [];
        $skippedDup = [];
        foreach ($page['links'] as $link) {
            if ($depth + 1 > self::MAX_DEPTH) {
                $skippedDup[] = ['url' => $link, 'reason' => 'trap-depth']; // 防爬蟲陷阱
                continue;
            }
            if ($this->seenBefore($state, $link)) {
                $skippedDup[] = ['url' => $link, 'reason' => 'dedup'];
                continue;
            }
            $this->markSeen($state, $link);
            $this->enqueue($state, $link, $depth + 1);
            $added[] = ['url' => $link, 'shard' => $this->shardOf($link)];
        }

        return [
            'type' => 'fetched',
            'clock' => $clock,
            'url' => $url,
            'domain' => $domain,
            'shard' => $shard,
            'depth' => $depth,
            'wasDeferred' => $deferred,
            'content' => $page['content'],
            'addedToFrontier' => $added,
            'skipped' => $skippedDup,
        ];
    }

    /** 到期頁面放回 frontier 重抓（簡化 freshness 排程）。 */
    private function scheduleRecrawls(array &$state, int $clock): void
    {
        foreach ($state['visited'] as $url => $info) {
            $due = $info['nextRecrawlAt'] ?? PHP_INT_MAX;
            if ($clock >= $due && !$this->inFrontier($state, $url)) {
                // 重抓不需重新去重（本來就爬過），直接入列；推遲下次到期避免每步重排。
                $this->enqueue($state, $url, $info['depth'] ?? 0);
                $state['visited'][$url]['nextRecrawlAt'] = $clock + self::RECRAWL_INTERVAL;
            }
        }
    }

    /** 最近一筆「未在 frontier 中、排了未來重抓」的到期時間；無則回 null。 */
    private function nextRecrawlDue(array $state): ?int
    {
        $min = null;
        foreach ($state['visited'] as $url => $info) {
            $due = $info['nextRecrawlAt'] ?? null;
            if ($due === null || $this->inFrontier($state, $url)) {
                continue;
            }
            if ($min === null || $due < $min) {
                $min = $due;
            }
        }
        return $min;
    }

    // ============================================================
    // Frontier（分散式分片佇列）
    // ============================================================

    /** 依 domain 雜湊決定 shard（同 domain → 同 shard，確保 politeness 集中）。 */
    private function shardOf(string $url): int
    {
        $domain = $this->domainOf($url);
        return crc32($domain) % self::SHARD_COUNT;
    }

    private function enqueue(array &$state, string $url, int $depth, bool $deferred = false): void
    {
        $shard = $this->shardOf($url);
        $state['frontier'][$shard][] = ['url' => $url, 'depth' => $depth, 'deferred' => $deferred];
        if (!$deferred) {
            $this->markSeen($state, $url); // seed/新發現的也算已見過
        }
    }

    /** 輪詢 shard 取出隊首；回 null 代表所有 shard 皆空。 */
    private function dequeue(array &$state, int $clock): ?array
    {
        $n = self::SHARD_COUNT;
        for ($off = 0; $off < $n; $off++) {
            $shard = ($state['rrCursor'] + $off) % $n;
            if (!empty($state['frontier'][$shard])) {
                $item = array_shift($state['frontier'][$shard]);
                $state['rrCursor'] = ($shard + 1) % $n; // 下次從下一個 shard 開始（公平）
                return [
                    'url' => $item['url'],
                    'depth' => $item['depth'],
                    'shard' => $shard,
                    'deferred' => $item['deferred'] ?? false,
                ];
            }
        }
        return null;
    }

    private function inFrontier(array $state, string $url): bool
    {
        foreach ($state['frontier'] as $queue) {
            foreach ($queue as $item) {
                if ($item['url'] === $url) {
                    return true;
                }
            }
        }
        return false;
    }

    // ============================================================
    // URL 去重（bloom filter + 精確 seen 集合二次確認）
    // ============================================================

    private function seenBefore(array &$state, string $url): bool
    {
        $bloom = BloomFilter::fromArray($state['bloom']);
        if (!$bloom->mightContain($url)) {
            return false; // bloom 說一定沒見過 → 直接當新 url（無偽陰性）
        }
        // bloom 說「可能見過」→ 查精確 seen 二次確認（擋掉偽陽性）。
        $state['bloomQueries']++;
        $exact = isset($state['seen'][$url]);
        if (!$exact) {
            $state['bloomFalsePositives']++; // bloom 說可能、精確說沒有 → 一次偽陽性
        }
        return $exact;
    }

    private function markSeen(array &$state, string $url): void
    {
        if (isset($state['seen'][$url])) {
            return;
        }
        $state['seen'][$url] = true;
        $bloom = BloomFilter::fromArray($state['bloom']);
        $bloom->add($url);
        $state['bloom'] = $bloom->toArray();
    }

    // ============================================================
    // 假網路圖（process 內，確定性；不連真網路）
    // ============================================================

    /**
     * 小型假網路：橫跨 3 個 domain，含跨站連結、回連（製造重複）與一條較深的鏈。
     * @return array<string,array{content:string,links:string[]}>
     */
    private function sampleWeb(): array
    {
        return [
            // ---- news.example ----
            'http://news.example/home' => [
                'content' => '新聞首頁',
                'links' => [
                    'http://news.example/tech',
                    'http://news.example/world',
                    'http://blog.example/post-1',
                ],
            ],
            'http://news.example/tech' => [
                'content' => '科技版',
                'links' => [
                    'http://news.example/tech/ai',
                    'http://news.example/home',      // 回連 → 應被去重擋掉
                    'http://shop.example/item-1',
                ],
            ],
            'http://news.example/tech/ai' => [
                'content' => 'AI 專題',
                'links' => ['http://news.example/tech', 'http://blog.example/post-2'],
            ],
            'http://news.example/world' => [
                'content' => '國際版',
                'links' => ['http://news.example/home', 'http://blog.example/post-1'],
            ],
            // ---- blog.example ----
            'http://blog.example/post-1' => [
                'content' => '部落格文章 1',
                'links' => ['http://blog.example/post-2', 'http://shop.example/item-2'],
            ],
            'http://blog.example/post-2' => [
                'content' => '部落格文章 2',
                'links' => ['http://blog.example/post-3', 'http://news.example/tech'],
            ],
            'http://blog.example/post-3' => [
                'content' => '部落格文章 3（鏈尾）',
                'links' => ['http://blog.example/post-1'], // 回連製造環
            ],
            // ---- shop.example ----
            'http://shop.example/item-1' => [
                'content' => '商品 1',
                'links' => ['http://shop.example/item-2', 'http://shop.example/item-3'],
            ],
            'http://shop.example/item-2' => [
                'content' => '商品 2',
                'links' => ['http://shop.example/item-1'],
            ],
            'http://shop.example/item-3' => [
                'content' => '商品 3',
                'links' => ['http://shop.example/item-1', 'http://news.example/home'],
            ],
        ];
    }

    /** 取出 URL 的 domain（host）。 */
    private function domainOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'unknown';
    }

    // ============================================================
    // 狀態：建立 / 讀寫（flock）/ 對外快照
    // ============================================================

    private function freshState(): array
    {
        $frontier = [];
        for ($i = 0; $i < self::SHARD_COUNT; $i++) {
            $frontier[$i] = [];
        }
        $bloom = (new BloomFilter(self::BLOOM_M, self::BLOOM_K))->toArray();
        return [
            'clock' => 0,
            'rrCursor' => 0,
            'frontier' => $frontier,
            'visited' => [],   // url => {depth, fetchedAt, nextRecrawlAt, fetchCount, outLinks}
            'seen' => [],      // url => true（精確去重集合）
            'domains' => [],   // domain => {lastFetch}
            'bloom' => $bloom,
            'bloomQueries' => 0,
            'bloomFalsePositives' => 0,
        ];
    }

    /** 對外狀態快照（給前端渲染）。 */
    private function publicState(array $state): array
    {
        $frontier = [];
        $frontierTotal = 0;
        foreach ($state['frontier'] as $shard => $queue) {
            $items = array_map(
                fn($it) => [
                    'url' => $it['url'],
                    'depth' => $it['depth'],
                    'domain' => $this->domainOf($it['url']),
                    'deferred' => $it['deferred'] ?? false,
                ],
                $queue
            );
            $frontier[] = ['shard' => $shard, 'size' => count($items), 'items' => $items];
            $frontierTotal += count($items);
        }

        $visited = [];
        foreach ($state['visited'] as $url => $info) {
            $visited[] = [
                'url' => $url,
                'domain' => $this->domainOf($url),
                'depth' => $info['depth'] ?? 0,
                'fetchedAt' => $info['fetchedAt'] ?? 0,
                'nextRecrawlAt' => $info['nextRecrawlAt'] ?? 0,
                'fetchCount' => $info['fetchCount'] ?? 1,
            ];
        }

        $domains = [];
        foreach ($state['domains'] as $d => $info) {
            $domains[] = [
                'domain' => $d,
                'lastFetch' => $info['lastFetch'] ?? null,
                'shard' => crc32($d) % self::SHARD_COUNT,
            ];
        }

        $bloom = BloomFilter::fromArray($state['bloom'])->stats();
        $bloom['lookups'] = $state['bloomQueries'];
        $bloom['confirmed_false_positives'] = $state['bloomFalsePositives'];

        return [
            'clock' => $state['clock'],
            'config' => [
                'shardCount' => self::SHARD_COUNT,
                'crawlDelay' => self::CRAWL_DELAY,
                'maxDepth' => self::MAX_DEPTH,
                'recrawlInterval' => self::RECRAWL_INTERVAL,
            ],
            'frontier' => $frontier,
            'frontierTotal' => $frontierTotal,
            'visited' => $visited,
            'visitedCount' => count($visited),
            'seenCount' => count($state['seen']),
            'domains' => $domains,
            'bloom' => $bloom,
        ];
    }

    private function readState(): array
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            return $this->freshState();
        }
        flock($fp, LOCK_SH);
        $raw = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $state = json_decode($raw, true);
        return is_array($state) && isset($state['frontier']) ? $state : $this->freshState();
    }

    private function writeState(array $state): void
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
