<?php
declare(strict_types=1);

/**
 * 廣告點擊聚合 / 計費 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8022 -t public
 *
 * 管線：點擊事件 ─▶ ClickIngest(依 click_id 去重) ─▶ FraudFilter(同IP洪水剔除)
 *                 ─▶ Aggregator(每分鐘 tumbling window 累計可計費點擊)
 *
 * 路由：
 *   GET  /                       深色測試頁（一鍵跑示範管線、查報表）
 *   POST /api/ingest             餵入一批點擊事件（JSON 陣列）→ 回管線統計
 *   POST /api/demo               用內建範例批次（含重複 click_id + 某 IP 洪水）跑一遍
 *   GET  /api/report?ad_id=...   查某廣告分視窗計數與總計費
 *   GET  /api/report             查所有廣告聚合明細 + 全域漏斗統計
 *   POST /api/reset              清空狀態（去重集合 / 風控歷史 / 聚合視窗）
 */

require __DIR__ . '/../src/ClickIngest.php';
require __DIR__ . '/../src/FraudFilter.php';
require __DIR__ . '/../src/Aggregator.php';

// ---- 狀態持久化（JSON 檔模擬串流算子的 keyed-state；跨請求保留管線狀態）----
$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}
$stateFile = $dataDir . '/pipeline.json';

$ingest = new ClickIngest();
$fraud  = new FraudFilter(10, 5);   // 同 IP 10 秒內超過 5 次即判洪水
$agg    = new Aggregator(60);       // 每 60 秒一個滾動視窗
$raw    = 0;                        // 累計收到的原始事件數

if (is_file($stateFile)) {
    $s = json_decode((string) file_get_contents($stateFile), true) ?: [];
    $ingest->loadSeen($s['seen'] ?? []);
    $agg->load($s['windows'] ?? []);
    $raw = (int) ($s['raw'] ?? 0);
    // 注意：FraudFilter 的滑動視窗歷史為示範簡化，不跨請求持久化
}

function save_state(string $file, ClickIngest $ingest, Aggregator $agg, int $raw): void
{
    file_put_contents($file, json_encode([
        'seen' => $ingest->seenSet(),
        'windows' => $agg->snapshot(),
        'raw' => $raw,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

/**
 * 核心管線：把一批事件依序通過 去重 → 風控 → 聚合，回傳各階段漏斗統計。
 */
function run_pipeline(array $events, ClickIngest $ingest, FraudFilter $fraud, Aggregator $agg): array
{
    $rawCount = count($events);
    $afterDedup = 0;
    foreach ($events as $e) {
        if (!is_array($e)) {
            continue;
        }
        // 1) 冪等去重：重複 click_id 直接丟棄（exactly-once 計費）
        if (!$ingest->ingest($e)) {
            continue;
        }
        $afterDedup++;
        // 2) 欺詐過濾：同 IP 短視窗洪水點擊剔除
        if (!$fraud->isLegit($e)) {
            continue;
        }
        // 3) 視窗聚合：累計可計費點擊（event-time tumbling window）
        $agg->add($e);
    }
    return [
        'raw' => $rawCount,
        'after_dedup' => $afterDedup,
        'dropped_duplicate' => $rawCount - $afterDedup,
        'dropped_fraud' => $fraud->blockedCount(),
        'billable' => $agg->billableCount(),
    ];
}

/** 內建範例批次：5 個廣告的正常點擊 + 重複 click_id + 某 IP 對 ad_1 的洪水點擊 */
function sample_events(): array
{
    $base = 1717200000; // 固定基準時間，視窗可重現
    $events = [];
    $n = 1;
    // 正常點擊：ad_1..ad_3，不同 IP，分散在兩個 60 秒視窗
    foreach (['ad_1' => 3, 'ad_2' => 2, 'ad_3' => 4] as $ad => $cnt) {
        for ($i = 0; $i < $cnt; $i++) {
            $events[] = ['click_id' => 'c' . $n, 'ad_id' => $ad, 'ip' => '10.0.0.' . $n, 'ts' => $base + $i * 25];
            $n++;
        }
    }
    // 重複投遞：把前 3 筆（c1,c2,c3）原封不動再送一次（at-least-once 副作用）
    $events[] = ['click_id' => 'c1', 'ad_id' => 'ad_1', 'ip' => '10.0.0.1', 'ts' => $base];
    $events[] = ['click_id' => 'c2', 'ad_id' => 'ad_1', 'ip' => '10.0.0.2', 'ts' => $base + 25];
    $events[] = ['click_id' => 'c3', 'ad_id' => 'ad_1', 'ip' => '10.0.0.3', 'ts' => $base + 50];
    // 洪水攻擊：同一個 IP 1.2.3.4 在 5 秒內對 ad_1 連點 12 次（click_id 皆不同）
    for ($i = 0; $i < 12; $i++) {
        $events[] = ['click_id' => 'f' . $i, 'ad_id' => 'ad_1', 'ip' => '1.2.3.4', 'ts' => $base + $i];
    }
    return $events;
}

// ---- POST /api/ingest：餵入自訂批次 ----
if ($method === 'POST' && $path === '/api/ingest') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $events = is_array($body) ? ($body['events'] ?? $body) : [];
    if (!is_array($events)) {
        http_response_code(400);
        exit(json_out(['error' => 'body 需為事件陣列或 {events:[...]}']));
    }
    $stats = run_pipeline($events, $ingest, $fraud, $agg);
    $raw += $stats['raw'];
    save_state($stateFile, $ingest, $agg, $raw);
    exit(json_out(['funnel' => $stats, 'total_raw_seen' => $raw]));
}

// ---- POST /api/demo：跑內建範例批次 ----
if ($method === 'POST' && $path === '/api/demo') {
    $events = sample_events();
    $stats = run_pipeline($events, $ingest, $fraud, $agg);
    $raw += $stats['raw'];
    save_state($stateFile, $ingest, $agg, $raw);
    exit(json_out([
        'note' => '範例含 3 筆重複 click_id（去重剔除）與 1.2.3.4 對 ad_1 的 12 次洪水（風控剔除）',
        'funnel' => $stats,
        'report' => $agg->reportAll(),
    ]));
}

// ---- POST /api/reset：清空 ----
if ($method === 'POST' && $path === '/api/reset') {
    $ingest->reset();
    $fraud->reset();
    $agg->reset();
    @unlink($stateFile);
    exit(json_out(['ok' => true]));
}

// ---- GET /api/report：報表 ----
if ($method === 'GET' && $path === '/api/report') {
    $adId = trim((string) ($_GET['ad_id'] ?? ''));
    if ($adId !== '') {
        exit(json_out($agg->report($adId)));
    }
    exit(json_out([
        'window_sec' => $agg->windowSec(),
        'fraud_rule' => ['window_sec' => $fraud->windowSec(), 'threshold' => $fraud->threshold()],
        'total_raw_seen' => $raw,
        'report' => $agg->reportAll(),
    ]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home();
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function render_home(): void
{
    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>廣告點擊聚合 / 計費 · Demo</title>
<style>
body{max-width:820px;margin:36px auto;padding:0 16px;background:#0f1419;color:#e6edf3;
  font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.7}
h1{font-size:1.6rem}h2{font-size:1.15rem;border-bottom:1px solid #2a3540;padding-bottom:.3em;margin-top:1.6em}
a{color:#58a6ff}code{background:#161b22;padding:2px 6px;border-radius:6px;color:#7ee787}
button{background:#1a2027;color:#e6edf3;border:1px solid #2a3540;border-radius:8px;
  padding:8px 16px;cursor:pointer;font-size:.95rem;margin:4px 6px 4px 0}
button:hover{border-color:#58a6ff}
.box{border-left:4px solid #58a6ff;background:#1a2027;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box.warn{border-color:#f0883e}.box.tip{border-color:#7ee787}
.box .label{font-weight:700;display:block;margin-bottom:.2em}
pre{background:#161b22;border:1px solid #2a3540;border-radius:12px;padding:14px 16px;overflow-x:auto;font-size:.85rem}
table{width:100%;border-collapse:collapse;margin:.6em 0;font-size:.9rem}
th,td{border:1px solid #2a3540;padding:8px 10px;text-align:left}th{background:#1a2027;color:#58a6ff}
</style></head><body>
<h1>📊 廣告點擊聚合 / 計費 · Demo</h1>
<p>管線：<code>點擊事件 → 去重(click_id) → 欺詐過濾(同IP洪水) → 視窗聚合(每60秒)</code><br>
聚焦 <strong>exactly-once 計費 + 防點擊欺詐 + tumbling window 聚合</strong>，非 CRUD。</p>

<div class="box tip"><span class="label">一鍵示範</span>
範例批次含：5 個廣告的正常點擊、<strong>3 筆重複 click_id</strong>（應被去重剔除）、
以及 <code>1.2.3.4</code> 對 <code>ad_1</code> 的 <strong>12 次洪水點擊</strong>（10 秒滑動視窗、門檻 5 → 攔下 5 筆）。
觀察「原始事件數 vs 去重後 vs 可計費數」的漏斗。</div>

<button onclick="demo()">▶ 跑範例批次</button>
<button onclick="report()">📈 看聚合報表</button>
<button onclick="reset()">🗑 清空狀態</button>

<h2>結果</h2>
<pre id="out">（點上面按鈕）</pre>

<div class="box warn"><span class="label">誠實聲明</span>
Demo 為<strong>單機單行程</strong>，用 JSON 檔保存管線狀態以示意，並非真的接 Kafka / Flink / OLAP 叢集；
但 <strong>click_id 冪等去重（exactly-once 計費）、同 IP 滑動視窗洪水偵測、event-time tumbling window 聚合</strong>
皆為真實可執行的邏輯，且報表直接呈現「原始 vs 去重後 vs 計費」的差距。
FraudFilter 的滑動視窗歷史為示範簡化、不跨請求持久化，故每次跑範例的風控結果可重現。</div>

<script>
const out = document.getElementById('out');
const show = d => out.textContent = JSON.stringify(d, null, 2);
async function demo(){ show(await (await fetch('/api/demo',{method:'POST'})).json()); }
async function report(){ show(await (await fetch('/api/report')).json()); }
async function reset(){ show(await (await fetch('/api/reset',{method:'POST'})).json()); }
</script>
</body></html>
HTML;
}
