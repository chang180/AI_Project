<?php
declare(strict_types=1);

/**
 * OLAP / 列式儲存 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8054 -t public
 *
 * 路由：
 *   GET  /                 測試頁（載入資料 → 跑聚合查詢看「只掃需要欄」+ 對比行式掃描量 + 壓縮效果）
 *   POST /api/load         產生 N 筆樣本事件並以「列式」入庫  { rows? }
 *   GET  /api/query        跑聚合 ?select=amount&groupby=region&agg=sum
 *   GET  /api/state        目前列數 / schema / 各欄壓縮統計
 */

require __DIR__ . '/../src/ColumnStore.php';
require __DIR__ . '/../src/Compressor.php';
require __DIR__ . '/../src/QueryEngine.php';

$store  = new ColumnStore(__DIR__ . '/../data');
$engine = new QueryEngine($store);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/load：產生樣本並列式入庫 ----
if ($method === 'POST' && $path === '/api/load') {
    $n = (int) ($_POST['rows'] ?? 0);
    if ($n <= 0 && ($raw = file_get_contents('php://input'))) {
        $n = (int) (json_decode($raw, true)['rows'] ?? 0);
    }
    if ($n <= 0) { $n = 2000; }
    $n = min($n, 200000);
    $store->reset();
    $store->loadRows(sample_rows($n));
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        header('Location: /?loaded=' . $n);
        exit;
    }
    exit(json_out(['loaded' => $n, 'row_count' => $store->rowCount()]));
}

// ---- GET /api/query：聚合查詢 ----
if ($method === 'GET' && $path === '/api/query') {
    $select  = (string) ($_GET['select'] ?? 'amount');
    $groupby = (string) ($_GET['groupby'] ?? 'region');
    $agg     = (string) ($_GET['agg'] ?? 'sum');
    if (!valid_col($store, $select, true) || !valid_col($store, $groupby, true)) {
        http_response_code(400);
        exit(json_out(['error' => 'select/groupby 必須是已知欄位或空字串']));
    }
    if (!in_array(strtolower($agg), ['sum', 'avg', 'count', 'min', 'max'], true)) {
        http_response_code(400);
        exit(json_out(['error' => 'agg 必須是 sum/avg/count/min/max']));
    }
    exit(json_out($engine->run($select, $groupby, $agg)));
}

// ---- GET /api/state：狀態 + 壓縮統計 ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out(state_payload($store)));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($store, $engine);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
function valid_col(ColumnStore $store, string $col, bool $allowEmpty): bool
{
    if ($col === '') { return $allowEmpty; }
    return in_array($col, $store->columnNames(), true);
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** 產生 N 筆樣本事件（交易明細）：ts 單調遞增、region/device 低基數（利字典/RLE） */
function sample_rows(int $n): array
{
    $regions = ['TW', 'JP', 'US', 'SG'];      // 低基數 → 字典編碼超省
    $devices = ['ios', 'android', 'web'];
    $rows = [];
    $ts = 1_700_000_000;
    for ($i = 0; $i < $n; $i++) {
        $ts += random_int(0, 3);               // 近似單調遞增 → delta 編碼好壓
        $rows[] = [
            'ts'     => $ts,
            'region' => $regions[$i % 4],
            'device' => $devices[$i % 3],
            'amount' => random_int(50, 5000) + random_int(0, 99) / 100,
        ];
    }
    return $rows;
}

function state_payload(ColumnStore $store): array
{
    $schema = $store->schema();
    $cols = [];
    $totalRaw = 0; $totalComp = 0;
    foreach ($schema as $name => $type) {
        $vals = $store->column($name);
        $a = Compressor::analyzeColumn($name, $type, $vals);
        $totalRaw += $a['raw_bytes'];
        $totalComp += $a['compressed_bytes'];
        $cols[] = $a;
    }
    return [
        'row_count'    => $store->rowCount(),
        'schema'       => $schema,
        'columns'      => $cols,
        'total_raw_bytes'        => $totalRaw,
        'total_compressed_bytes' => $totalComp,
        'overall_ratio' => $totalComp > 0 ? round($totalRaw / $totalComp, 2) : 0,
    ];
}

function render_home(ColumnStore $store, QueryEngine $engine): void
{
    $loaded = $_GET['loaded'] ?? null;
    $rc = $store->rowCount();

    // 若已有資料，跑一個範例查詢展示「只掃 2 欄 vs 行式掃全部欄」
    $demoBlock = '';
    if ($rc > 0) {
        $r = $engine->run('amount', 'region', 'sum');
        $s = $r['scan'];
        $gRows = '';
        foreach ($r['groups'] as $g) {
            $gRows .= "<tr><td>{$g['key']}</td><td>{$g['value']}</td><td>{$g['rows']}</td></tr>";
        }
        $usedList = implode(', ', $s['columns_used']);
        $st = state_payload($store);
        $cRows = '';
        foreach ($st['columns'] as $c) {
            $cRows .= "<tr><td><code>{$c['column']}</code></td><td>{$c['encoding']}</td>"
                . "<td>{$c['raw_bytes']}</td><td>{$c['compressed_bytes']}</td><td>{$c['ratio']}×</td></tr>";
        }
        $demoBlock = <<<HTML
<div class="box"><span class="label">範例查詢：SELECT SUM(amount) GROUP BY region</span>
<p>用到的欄：<code>$usedList</code>（共 {$s['columns_scanned']} 欄）；全表共 {$s['total_columns']} 欄。</p>
<table><tr><th>region</th><th>SUM(amount)</th><th>列數</th></tr>$gRows</table>
</div>
<div class="box tip"><span class="label">掃描量對比（本題核心考點）</span>
<table>
<tr><th></th><th>掃描格子數（列×欄）</th></tr>
<tr><td>列式（只掃 {$s['columns_scanned']} 欄）</td><td><strong>{$s['columnar_cells']}</strong></td></tr>
<tr><td>行式（被迫讀整列 {$s['total_columns']} 欄）</td><td>{$s['rowstore_cells']}</td></tr>
</table>
<p>👉 行式要多掃 <strong>{$s['rowstore_saving_x']}×</strong> 的資料才能得到同一個答案。</p>
</div>
<div class="box"><span class="label">欄壓縮效果（整體 {$st['overall_ratio']}×）</span>
<table><tr><th>欄</th><th>編碼</th><th>原始 bytes</th><th>壓縮後</th><th>壓縮率</th></tr>$cRows</table>
</div>
HTML;
    }

    $banner = $loaded
        ? "<div class='box tip'><span class='label'>已列式載入 $loaded 筆</span>每欄拆成獨立連續陣列。下方查詢只會掃用到的欄。</div>"
        : "<div class='box'><span class='label'>尚無資料</span>先按「載入樣本」產生交易事件並以列式入庫。</div>";

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OLAP / 列式儲存 · Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>body{max-width:820px;margin:40px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box.tip{border-left-color:#79c0ff}
.box .label{font-weight:700;display:block;margin-bottom:6px}code{background:#222;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse;margin:.4em 0}td,th{border:1px solid #444;padding:6px 8px;text-align:left}
form{display:inline}</style>
</head><body>
<h1>📊 OLAP / 列式儲存 · Demo</h1>
<p>分析型查詢（SUM/AVG … GROUP BY）只掃<strong>用到的欄</strong>；對比行式被迫讀<strong>整列</strong>的掃描量差異，並看欄壓縮效果。目前列數：<strong>$rc</strong>。</p>
$banner
<form method="post" action="/api/load">
  <input type="hidden" name="rows" value="2000">
  <button type="submit">載入樣本（2000 筆）</button>
</form>
<form method="post" action="/api/load">
  <input type="hidden" name="rows" value="20000">
  <button type="submit">載入樣本（20000 筆）</button>
</form>
$demoBlock
<h2>自己跑查詢</h2>
<p>改網址參數即可：<code>/api/query?select=amount&groupby=region&agg=sum</code></p>
<ul>
<li><a href="/api/query?select=amount&groupby=region&agg=sum">SUM(amount) GROUP BY region</a></li>
<li><a href="/api/query?select=amount&groupby=device&agg=avg">AVG(amount) GROUP BY device</a></li>
<li><a href="/api/query?select=&groupby=region&agg=count">COUNT(*) GROUP BY region</a></li>
<li><a href="/api/query?select=amount&groupby=&agg=max">MAX(amount)（全表，不分組）</a></li>
<li><a href="/api/state">/api/state（schema + 各欄壓縮統計）</a></li>
</ul>
<p style="color:#888;font-size:.85rem">※ 記憶體 + JSON 檔模擬列式儲存；「只掃需要欄 / 壓縮率 / 向量化聚合 / 掃描量對比」皆為真實計算。</p>
</body></html>
HTML;
}
