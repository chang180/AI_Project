<?php
declare(strict_types=1);

/**
 * TraceStore — 分散式追蹤系統核心邏輯
 * ----------------------------------------------------------------
 * 真實系統：應用埋點產生 span → OTLP 上報 → 收集器(Collector) → 儲存(Cassandra/ES)
 *           → 查詢服務組裝成 trace 樹 → UI 火焰圖。
 *
 * 這支檔案把「最考觀念」的三件事做成真實可執行邏輯：
 *   1. 由一堆「扁平 span」依 parent_span_id 重建呼叫樹（call tree）。
 *   2. 算整條 trace 的總時間，並找出「關鍵路徑 / 最慢的 span」。
 *   3. head-based 取樣：依 trace_id 雜湊決定是否取樣（1/N），呈現取樣率。
 *
 * 儲存以 JSON 檔模擬，寫入用 flock 保護共享狀態。
 *
 * span 欄位：
 *   trace_id        一整條請求鏈共用的 ID
 *   span_id         單一工作單元的 ID
 *   parent_span_id  上一層 span（根 span 為空字串）
 *   service         服務名（如 gateway / order / payment）
 *   name            操作名（如 GET /checkout、SELECT orders）
 *   start_ms        起始時間（毫秒，相對起點即可）
 *   duration_ms     耗時（毫秒）
 */
final class TraceStore
{
    private string $dbFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dbFile = $dataDir . '/spans.json';
        if (!file_exists($this->dbFile)) {
            file_put_contents($this->dbFile, '{}');
        }
    }

    // ============================================================
    // 寫入：批次收 span
    // ============================================================

    /**
     * 批次寫入 span（模擬 collector 收 OTLP 批次）。
     * 以 trace_id 分桶儲存：{ trace_id: [span, span, ...] }。
     *
     * @param array<int,array<string,mixed>> $spans
     * @return array{accepted:int, traces:array<int,string>}
     */
    public function ingest(array $spans): array
    {
        $fp = fopen($this->dbFile, 'c+');
        flock($fp, LOCK_EX);
        $raw = (string) stream_get_contents($fp);
        $db = json_decode($raw === '' ? '{}' : $raw, true) ?: [];

        $accepted = 0;
        $touched = [];
        foreach ($spans as $s) {
            $span = $this->normalizeSpan($s);
            if ($span === null) {
                continue; // 缺必要欄位 → 丟棄
            }
            $tid = $span['trace_id'];
            $db[$tid] ??= [];
            $db[$tid][] = $span;
            $touched[$tid] = true;
            $accepted++;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return ['accepted' => $accepted, 'traces' => array_keys($touched)];
    }

    /** 把外來 span 正規化；缺 trace_id/span_id 視為非法。 */
    private function normalizeSpan(array $s): ?array
    {
        $traceId = trim((string) ($s['trace_id'] ?? ''));
        $spanId  = trim((string) ($s['span_id'] ?? ''));
        if ($traceId === '' || $spanId === '') {
            return null;
        }
        return [
            'trace_id'       => $traceId,
            'span_id'        => $spanId,
            'parent_span_id' => trim((string) ($s['parent_span_id'] ?? '')),
            'service'        => trim((string) ($s['service'] ?? 'unknown')),
            'name'           => trim((string) ($s['name'] ?? 'unnamed')),
            'start_ms'       => (float) ($s['start_ms'] ?? 0),
            'duration_ms'    => max(0.0, (float) ($s['duration_ms'] ?? 0)),
        ];
    }

    /** 取某條 trace 的所有原始 span。 */
    public function rawSpans(string $traceId): array
    {
        $db = $this->readDb();
        return $db[$traceId] ?? [];
    }

    public function allTraceIds(): array
    {
        return array_keys($this->readDb());
    }

    // ============================================================
    // 核心 1：由扁平 span 重建呼叫樹
    // ============================================================

    /**
     * 把扁平 span 陣列重建為呼叫樹。
     * 用 parent_span_id 把每個 span 掛到父 span 的 children 底下；
     * parent 為空（或父不存在）者視為根。
     *
     * @param array<int,array<string,mixed>> $spans
     * @return array{roots:array<int,array<string,mixed>>, count:int, orphans:int}
     */
    public function buildTree(array $spans): array
    {
        // 先建 id → node 索引（node 帶 children 容器）
        $nodes = [];
        foreach ($spans as $s) {
            $node = $s;
            $node['children'] = [];
            $nodes[$s['span_id']] = $node;
        }

        $roots = [];
        $orphans = 0;
        // 第二輪：依 parent 關係掛載
        foreach ($spans as $s) {
            $sid = $s['span_id'];
            $pid = $s['parent_span_id'];
            if ($pid !== '' && isset($nodes[$pid])) {
                $nodes[$pid]['children'][] = &$nodes[$sid];
            } else {
                if ($pid !== '') {
                    $orphans++; // 有父 ID 但父 span 不在這批（取樣/掉包）→ 當成根接上
                }
                $roots[] = &$nodes[$sid];
            }
        }

        // 每層 children 依 start_ms 排序，火焰圖才會由左到右照時間排
        foreach ($nodes as &$n) {
            usort($n['children'], static fn($a, $b) => $a['start_ms'] <=> $b['start_ms']);
        }
        unset($n);
        usort($roots, static fn($a, $b) => $a['start_ms'] <=> $b['start_ms']);

        return ['roots' => $roots, 'count' => count($spans), 'orphans' => $orphans];
    }

    // ============================================================
    // 核心 2：總時間 + 關鍵路徑 / 最慢 span
    // ============================================================

    /**
     * 分析一條 trace：
     *   total_ms     整條 trace 牆鐘時間（最大 end - 最小 start）
     *   slowest      self-time（獨佔時間）最大的 span（找真瓶頸；不是涵蓋全部的根 span）
     *   critical_path 關鍵路徑：從根 span 出發，每層挑「最晚結束的子 span」
     *                 一路往下，直到葉節點——這串 span 決定了整條 trace 何時完成。
     *
     * @param array<int,array<string,mixed>> $spans
     * @return array<string,mixed>
     */
    public function analyze(array $spans): array
    {
        if ($spans === []) {
            return ['total_ms' => 0.0, 'span_count' => 0, 'slowest' => null, 'critical_path' => []];
        }

        // 牆鐘時間
        $minStart = INF;
        $maxEnd = -INF;
        foreach ($spans as $s) {
            $minStart = min($minStart, $s['start_ms']);
            $maxEnd = max($maxEnd, $s['start_ms'] + $s['duration_ms']);
        }
        $total = $maxEnd - $minStart;

        // 最慢 span 用「self-time（獨佔時間）」= 自身 duration - 直接子 span duration 總和。
        // 為何不用總 duration？因為根 span 涵蓋全部一定最大、卻看不出瓶頸；
        // self-time 才能指出「時間真正花在哪個 span 自己身上」（如外部 API、慢查詢）。
        $childSum = [];   // span_id → 直接子 span duration 總和
        foreach ($spans as $s) {
            $pid = $s['parent_span_id'];
            if ($pid !== '') {
                $childSum[$pid] = ($childSum[$pid] ?? 0.0) + $s['duration_ms'];
            }
        }
        $slowest = null;
        $slowestSelf = -INF;
        foreach ($spans as $s) {
            $self = max(0.0, $s['duration_ms'] - ($childSum[$s['span_id']] ?? 0.0));
            if ($self > $slowestSelf) {
                $slowestSelf = $self;
                $slowest = $s;
                $slowest['self_ms'] = round($self, 2);
            }
        }

        $tree = $this->buildTree($spans);
        $critical = [];
        foreach ($tree['roots'] as $root) {
            $path = $this->walkCriticalPath($root);
            // 多根時取「結束最晚」的那條根鏈為整體關鍵路徑
            if ($critical === [] || $this->pathEnd($path) > $this->pathEnd($critical)) {
                $critical = $path;
            }
        }

        return [
            'total_ms'      => round($total, 2),
            'span_count'    => count($spans),
            'slowest'       => $slowest === null ? null : [
                'span_id'     => $slowest['span_id'],
                'service'     => $slowest['service'],
                'name'        => $slowest['name'],
                'duration_ms' => round($slowest['duration_ms'], 2),
                'self_ms'     => $slowest['self_ms'], // 獨佔時間（瓶頸指標）
            ],
            'critical_path' => array_map(static fn($n) => [
                'span_id'     => $n['span_id'],
                'service'     => $n['service'],
                'name'        => $n['name'],
                'duration_ms' => round($n['duration_ms'], 2),
            ], $critical),
        ];
    }

    /**
     * 走關鍵路徑：從這個 node 開始，每層挑「結束時間最晚」的子節點往下。
     * @return array<int,array<string,mixed>>
     */
    private function walkCriticalPath(array $node): array
    {
        $path = [$node];
        while (!empty($node['children'])) {
            $next = null;
            foreach ($node['children'] as $child) {
                $childEnd = $child['start_ms'] + $child['duration_ms'];
                if ($next === null || $childEnd > ($next['start_ms'] + $next['duration_ms'])) {
                    $next = $child;
                }
            }
            $path[] = $next;
            $node = $next;
        }
        return $path;
    }

    /** 路徑最後一個 span 的結束時間。 */
    private function pathEnd(array $path): float
    {
        if ($path === []) {
            return -INF;
        }
        $last = $path[count($path) - 1];
        return $last['start_ms'] + $last['duration_ms'];
    }

    // ============================================================
    // 核心 3：head-based 取樣
    // ============================================================

    /**
     * head-based 取樣決策：在 trace 一開始（看到 trace_id）就決定整條要不要留。
     * 同一條 trace 的所有服務只要用同一個 trace_id 算，就會得到一致決策
     * （要嘛整條都留、要嘛整條都丟），避免半截 trace。
     *
     * 作法：把 trace_id 雜湊成 0..(rate-1) 的桶，落在 0 桶就取樣 → 取樣率 1/rate。
     *
     * @return array{sampled:bool, rate:int, bucket:int}
     */
    public function sampleDecision(string $traceId, int $rate): array
    {
        $rate = max(1, $rate);
        // crc32 給穩定且均勻的整數；用無號處理避免負數取模
        $h = sprintf('%u', crc32($traceId));
        $bucket = (int) bcmodFallback($h, $rate);
        return [
            'sampled' => $bucket === 0,
            'rate'    => $rate,
            'bucket'  => $bucket,
        ];
    }

    private function readDb(): array
    {
        $json = (string) file_get_contents($this->dbFile);
        return json_decode($json === '' ? '{}' : $json, true) ?: [];
    }
}

/**
 * 對大數字字串做取模（避免 32/64 位元溢位；不依賴 gmp/bcmath）。
 * crc32 最大 ~42 億，在 64 位元 PHP 其實放得下 int，但為保險用字串逐位法。
 */
function bcmodFallback(string $num, int $mod): int
{
    $rem = 0;
    $len = strlen($num);
    for ($i = 0; $i < $len; $i++) {
        $d = (int) $num[$i];
        $rem = ($rem * 10 + $d) % $mod;
    }
    return $rem;
}
