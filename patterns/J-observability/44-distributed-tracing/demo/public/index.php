<?php
declare(strict_types=1);

/**
 * 分散式追蹤系統 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8044 -t public
 *
 * 路由：
 *   GET  /                       測試頁（餵一組 span、看重建的 trace 樹 + 各 span 耗時長條 + 標最慢、取樣示範）
 *   POST /api/spans              批次收 span  { spans: [...] }
 *   GET  /api/trace/{trace_id}   回樹狀 + 總時間 + 最慢 span + 關鍵路徑
 *   POST /api/sample             示範取樣決策  { trace_id, rate }
 *   POST /api/seed               寫入一組內建示範 span（測試頁按鈕用）
 */

require __DIR__ . '/../src/TraceStore.php';

$store = new TraceStore(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- POST /api/spans：批次收 span ----
if ($method === 'POST' && $path === '/api/spans') {
    $body = json_body();
    $spans = $body['spans'] ?? null;
    if (!is_array($spans)) {
        http_response_code(400);
        exit(json_out(['error' => 'body 需含 spans 陣列']));
    }
    $res = $store->ingest($spans);
    exit(json_out($res));
}

// ---- GET /api/trace/{trace_id}：回 trace 樹 + 分析 ----
if ($method === 'GET' && preg_match('#^/api/trace/([A-Za-z0-9_\-]+)$#', $path, $m)) {
    $spans = $store->rawSpans($m[1]);
    if ($spans === []) {
        http_response_code(404);
        exit(json_out(['error' => 'trace 不存在', 'trace_id' => $m[1]]));
    }
    $tree = $store->buildTree($spans);
    $analysis = $store->analyze($spans);
    exit(json_out([
        'trace_id' => $m[1],
        'analysis' => $analysis,
        'orphans'  => $tree['orphans'],
        'tree'     => $tree['roots'],
    ]));
}

// ---- POST /api/sample：取樣決策示範 ----
if ($method === 'POST' && $path === '/api/sample') {
    $body = json_body();
    $tid = trim((string) ($body['trace_id'] ?? ''));
    $rate = (int) ($body['rate'] ?? 10);
    if ($tid === '') {
        http_response_code(400);
        exit(json_out(['error' => '需提供 trace_id']));
    }
    exit(json_out($store->sampleDecision($tid, $rate)));
}

// ---- POST /api/seed：寫入內建示範資料 ----
if ($method === 'POST' && $path === '/api/seed') {
    $store->ingest(demo_spans());
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        header('Location: /?seeded=1');
        exit;
    }
    exit(json_out(['ok' => true, 'trace_id' => 'trace-demo-001']));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    // 確保有一份示範資料可看
    if ($store->rawSpans('trace-demo-001') === []) {
        $store->ingest(demo_spans());
    }
    render_home($store);
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

function json_body(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/**
 * 內建示範 trace：模擬一個 /checkout 請求跨 4 個服務。
 * gateway → order →（serial）payment、（serial）inventory；payment 內含一個慢的外部呼叫。
 * 關鍵路徑會落在最晚結束的那串。
 */
function demo_spans(): array
{
    $tid = 'trace-demo-001';
    return [
        // 根：gateway 處理整個請求
        ['trace_id' => $tid, 'span_id' => 'a', 'parent_span_id' => '',  'service' => 'gateway',   'name' => 'POST /checkout',        'start_ms' => 0,   'duration_ms' => 240],
        // order 服務
        ['trace_id' => $tid, 'span_id' => 'b', 'parent_span_id' => 'a', 'service' => 'order',     'name' => 'create_order',          'start_ms' => 10,  'duration_ms' => 220],
        // order 先查庫存（快）
        ['trace_id' => $tid, 'span_id' => 'c', 'parent_span_id' => 'b', 'service' => 'inventory', 'name' => 'reserve_stock',         'start_ms' => 20,  'duration_ms' => 30],
        // 再呼叫 payment（慢）
        ['trace_id' => $tid, 'span_id' => 'd', 'parent_span_id' => 'b', 'service' => 'payment',   'name' => 'charge',                'start_ms' => 60,  'duration_ms' => 165],
        // payment 內部呼叫外部金流（最慢的單一 span → 瓶頸）
        ['trace_id' => $tid, 'span_id' => 'e', 'parent_span_id' => 'd', 'service' => 'payment',   'name' => 'call_bank_api',         'start_ms' => 70,  'duration_ms' => 150],
        // payment 寫一筆交易紀錄（快）
        ['trace_id' => $tid, 'span_id' => 'f', 'parent_span_id' => 'd', 'service' => 'payment',   'name' => 'INSERT transactions',   'start_ms' => 222, 'duration_ms' => 3],
    ];
}

function render_home(TraceStore $store): void
{
    $tid = 'trace-demo-001';
    $spans = $store->rawSpans($tid);
    $tree = $store->buildTree($spans);
    $analysis = $store->analyze($spans);
    $seeded = isset($_GET['seeded']);

    // ---- 火焰圖長條：依 start/duration 換算成百分比 ----
    $total = max(1.0, (float) $analysis['total_ms']);
    $slowestId = $analysis['slowest']['span_id'] ?? '';
    $critIds = array_column($analysis['critical_path'], 'span_id');

    $bars = '';
    $rows = render_tree_rows($tree['roots'], 0, $total, $slowestId, $critIds, $bars);

    // 取樣示範表：幾個 trace_id 在 rate=10 下的決策
    $sampleRows = '';
    foreach (['trace-demo-001', 'trace-xyz-77', 'order-9f3a', 'req-2026-0601', 'cart-abc123'] as $t) {
        $d = $store->sampleDecision($t, 10);
        $mark = $d['sampled'] ? "<span style='color:#2ecc71'>✔ 取樣</span>" : "<span style='color:#888'>✘ 丟棄</span>";
        $sampleRows .= "<tr><td><code>" . htmlspecialchars($t) . "</code></td><td>bucket={$d['bucket']}</td><td>$mark</td></tr>";
    }

    $crit = implode(' → ', array_map(
        static fn($n) => htmlspecialchars($n['service'] . ':' . $n['name']),
        $analysis['critical_path']
    ));
    $slow = $analysis['slowest']
        ? htmlspecialchars($analysis['slowest']['service'] . ':' . $analysis['slowest']['name'])
          . " (self {$analysis['slowest']['self_ms']} ms / 總 {$analysis['slowest']['duration_ms']} ms)"
        : '—';
    $seedBanner = $seeded ? "<div class='box'><span class='label'>✅ 已重新寫入示範 span</span></div>" : '';

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>分散式追蹤 · Demo</title>
<style>
 body{max-width:900px;margin:36px auto;padding:0 18px;font-family:system-ui,"Microsoft JhengHei",sans-serif;color:#222;line-height:1.5}
 h1{font-size:1.5rem}h2{font-size:1.15rem;margin-top:1.8em;border-bottom:2px solid #eee;padding-bottom:4px}
 code{background:#f3f3f3;padding:1px 5px;border-radius:4px;font-size:.9em}
 .box{border-left:4px solid #4c8bf5;background:#f5f8ff;padding:10px 14px;border-radius:0 8px 8px 0;margin:1em 0}
 .box .label{font-weight:700;display:block}
 table{width:100%;border-collapse:collapse;margin:.6em 0}td,th{border:1px solid #ddd;padding:6px 8px;text-align:left;font-size:.92em}
 .flame{font-family:ui-monospace,monospace;font-size:.85rem}
 .bar{height:20px;border-radius:3px;display:inline-block;vertical-align:middle;min-width:2px}
 .lbl{display:inline-block;white-space:nowrap}
 .slowest{outline:2px solid #e5484d}
 .legend span{display:inline-block;margin-right:14px;font-size:.85em}
 button{background:#4c8bf5;color:#fff;border:0;padding:8px 16px;border-radius:6px;cursor:pointer}
 .track{background:#fafafa;border:1px solid #eee;border-radius:4px;position:relative}
</style>
</head><body>
<h1>🔍 分散式追蹤系統 · Demo</h1>
<p>一個 <code>POST /checkout</code> 請求跨 <strong>gateway → order → payment / inventory</strong> 四個服務。
下面這些扁平 span 被<strong>重建成呼叫樹</strong>，並算出總時間、標出<strong>最慢 span</strong> 與<strong>關鍵路徑</strong>。</p>
$seedBanner

<div class="box">
  <span class="label">📊 這條 trace 的分析（真實計算）</span>
  總時間 <strong>{$analysis['total_ms']} ms</strong>　·　span 數 <strong>{$analysis['span_count']}</strong>　·
  最慢 span：<strong>$slow</strong><br>
  關鍵路徑：<code>$crit</code>
</div>

<h2>🔥 呼叫樹 + 火焰圖（耗時長條）</h2>
<p class="legend">
  <span style="color:#e5484d">🟥 紅框＝最慢的單一 span</span>
  <span style="color:#f2b705">🟨 黃條＝在關鍵路徑上</span>
  <span style="color:#4c8bf5">🟦 藍條＝一般 span</span>
</p>
<table class="flame"><tr><th style="width:46%">span（依父子縮排）</th><th>時間軸（長條長度 = 耗時占比）</th></tr>
$rows
</table>

<h2>🎲 head-based 取樣示範（rate = 10，即取樣率 1/10）</h2>
<p>同一個 <code>trace_id</code> 永遠得到相同決策（整條 trace 一致取樣），靠 <code>crc32(trace_id) % rate == 0</code> 判定：</p>
<table><tr><th>trace_id</th><th>雜湊桶</th><th>決策</th></tr>$sampleRows</table>

<h2>🔧 API 自己玩</h2>
<form method="post" action="/api/seed">
  <button type="submit">重新寫入示範 span</button>
</form>
<pre style="background:#f7f7f7;padding:12px;border-radius:6px;font-size:.85em">
# 批次收 span
curl -X POST localhost:8044/api/spans -H "Content-Type: application/json" \\
  -d '{"spans":[{"trace_id":"t1","span_id":"a","service":"web","name":"GET /","start_ms":0,"duration_ms":50}]}'

# 查 trace 樹 + 總時間 + 最慢 span + 關鍵路徑
curl localhost:8044/api/trace/trace-demo-001

# 取樣決策
curl -X POST localhost:8044/api/sample -H "Content-Type: application/json" -d '{"trace_id":"t1","rate":10}'
</pre>
<p style="color:#888;font-size:.85rem">※ JSON 檔模擬儲存；trace 樹重建、總時間/最慢 span/關鍵路徑、head-based 取樣皆為真實可執行邏輯。</p>
</body></html>
HTML;
}

/**
 * 遞迴渲染 trace 樹的每一列（火焰圖長條）。
 * @param array<int,array<string,mixed>> $nodes
 */
function render_tree_rows(array $nodes, int $depth, float $total, string $slowestId, array $critIds, string &$_unused): string
{
    $out = '';
    foreach ($nodes as $n) {
        $indent = str_repeat('　', $depth);
        $left = ($n['start_ms'] / $total) * 100;
        $width = max(0.6, ($n['duration_ms'] / $total) * 100);
        $isSlow = $n['span_id'] === $slowestId;
        $isCrit = in_array($n['span_id'], $critIds, true);
        $color = $isCrit ? '#f2b705' : '#4c8bf5';
        $slowCls = $isSlow ? ' slowest' : '';
        $label = htmlspecialchars($n['service'] . ' · ' . $n['name']);
        $dur = round((float) $n['duration_ms'], 1);

        $out .= "<tr>"
            . "<td><span class='lbl'>{$indent}↳ <strong>{$label}</strong></span></td>"
            . "<td><div class='track'>"
            . "<span class='bar$slowCls' style='margin-left:{$left}%;width:{$width}%;background:$color'></span>"
            . " <span style='font-size:.8em;color:#666'>{$dur} ms</span>"
            . "</div></td></tr>";

        if (!empty($n['children'])) {
            $out .= render_tree_rows($n['children'], $depth + 1, $total, $slowestId, $critIds, $_unused);
        }
    }
    return $out;
}
