<?php
declare(strict_types=1);

/**
 * 指標監控系統（Prometheus）Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8027 -t public
 *
 * 路由：
 *   GET  /                 測試頁（模擬 target 推樣本、看 series、跑 rate/sum、看告警）
 *   POST /api/scrape       餵一批曝露格式文字（text/plain）或 JSON 樣本
 *   GET  /metrics          Prometheus 曝露格式輸出（本系統自身的 series 最新值）
 *   GET  /api/query?expr=  instant / rate / sum 簡化查詢
 *   GET  /api/alerts       告警狀態（評估規則後回傳狀態機快照）
 */

require __DIR__ . '/../src/Tsdb.php';

$tsdb = new Tsdb(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// 預設一條告警規則（每秒請求速率過高），首次啟動時註冊
ensure_default_rule($tsdb);

// ---- POST /api/scrape：餵樣本 ----
if ($method === 'POST' && $path === '/api/scrape') {
    $raw = (string) file_get_contents('php://input');
    $nowMs = now_ms();
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

    if (str_contains($contentType, 'application/json')) {
        // JSON 格式：{ "samples": [ {name,labels,value,type}, ... ] }
        $body = json_decode($raw, true);
        $samples = is_array($body['samples'] ?? null) ? $body['samples'] : [];
        $n = 0;
        foreach ($samples as $s) {
            if (!isset($s['name'], $s['value'])) {
                continue;
            }
            $tsdb->append(
                (string) $s['name'],
                is_array($s['labels'] ?? null) ? $s['labels'] : [],
                (float) $s['value'],
                $nowMs,
                in_array(($s['type'] ?? 'gauge'), ['counter', 'gauge'], true) ? (string) $s['type'] : 'gauge'
            );
            $n++;
        }
        exit(json_out(['ingested' => $n, 'ts' => $nowMs]));
    }

    // 預設：Prometheus 曝露格式文字
    if (trim($raw) === '' && isset($_POST['metrics'])) {
        $raw = (string) $_POST['metrics']; // 來自測試頁表單
    }
    $n = $tsdb->scrapeText($raw, $nowMs);
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        header('Location: /?scraped=' . $n);
        exit;
    }
    exit(json_out(['ingested' => $n, 'ts' => $nowMs]));
}

// ---- GET /metrics：曝露格式輸出 ----
if ($method === 'GET' && $path === '/metrics') {
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    echo $tsdb->exposition();
    exit;
}

// ---- GET /api/query?expr=：查詢 ----
if ($method === 'GET' && $path === '/api/query') {
    $expr = trim((string) ($_GET['expr'] ?? ''));
    $nowMs = now_ms();
    exit(json_out(run_query($tsdb, $expr, $nowMs)));
}

// ---- GET /api/alerts：告警狀態 ----
if ($method === 'GET' && $path === '/api/alerts') {
    exit(json_out(['alerts' => $tsdb->evalRules(now_ms())]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($tsdb);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 查詢解析 ============

/**
 * 支援的 expr 簡化語法：
 *   instant NAME{labels}            → 某 series 最新值
 *   rate NAME[windowSec]            → 各 series 每秒變化率
 *   sum NAME                        → 所有符合 series 最新值總和
 *   sum NAME by(label)              → 依 label 分組加總
 */
function run_query(Tsdb $tsdb, string $expr, int $nowMs): array
{
    if ($expr === '') {
        return ['error' => 'expr 為空'];
    }
    if (preg_match('/^instant\s+([a-zA-Z_:][a-zA-Z0-9_:]*)(\{[^}]*\})?$/', $expr, $m)) {
        $labels = labels_from($m[2] ?? '');
        $v = $tsdb->instant($m[1], $labels);
        return ['type' => 'instant', 'metric' => $m[1], 'labels' => $labels, 'value' => $v];
    }
    if (preg_match('/^rate\s+([a-zA-Z_:][a-zA-Z0-9_:]*)\[(\d+)\]$/', $expr, $m)) {
        $rates = $tsdb->rate($m[1], (int) $m[2], $nowMs);
        $out = [];
        foreach ($rates as $key => $r) {
            $out[$key] = round($r['rate'], 4);
        }
        return ['type' => 'rate', 'metric' => $m[1], 'windowSec' => (int) $m[2], 'series' => $out];
    }
    if (preg_match('/^sum\s+([a-zA-Z_:][a-zA-Z0-9_:]*)(?:\s+by\(([a-zA-Z_][a-zA-Z0-9_]*)\))?$/', $expr, $m)) {
        $by = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
        $groups = $tsdb->sumBy($m[1], $by, $nowMs);
        return ['type' => 'sum', 'metric' => $m[1], 'by' => $by, 'groups' => $groups];
    }
    return ['error' => '無法解析的 expr（支援 instant / rate NAME[sec] / sum NAME [by(label)]）'];
}

/** {a="1",b="2"} → ['a'=>'1','b'=>'2']（沿用 Tsdb 的標準格式） */
function labels_from(string $block): array
{
    $block = trim($block, '{}');
    $labels = [];
    if ($block !== '' && preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*"([^"]*)"/', $block, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $p) {
            $labels[$p[1]] = $p[2];
        }
    }
    return $labels;
}

// ============ 輔助函式 ============

function now_ms(): int
{
    return (int) round(microtime(true) * 1000);
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function ensure_default_rule(Tsdb $tsdb): void
{
    // for=0 → 條件成立即 firing，方便 demo 即時看到告警觸發
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $alerts = $tsdb->evalRules(now_ms());
    if (!isset($alerts['HighRequestRate'])) {
        $tsdb->addRule('HighRequestRate', 'rate http_requests_total[60] > 5', 0);
    }
}

function render_home(Tsdb $tsdb): void
{
    $scraped = $_GET['scraped'] ?? null;
    $nowMs = now_ms();

    // series 列表
    $seriesRows = '';
    foreach ($tsdb->listSeries() as $s) {
        $lbl = htmlspecialchars($tsdb->seriesKey($s['name'], $s['labels']));
        $seriesRows .= "<tr><td><code>$lbl</code></td><td>{$s['type']}</td>"
            . "<td>{$s['samples']}</td><td>" . htmlspecialchars((string) $s['last']) . "</td></tr>";
    }
    if ($seriesRows === '') {
        $seriesRows = "<tr><td colspan='4' style='color:#888'>尚無 series，請先用上方按鈕餵樣本</td></tr>";
    }

    // 範例曝露格式（測試頁 textarea 預填）
    $sampleText = "# TYPE http_requests_total counter\n"
        . "http_requests_total{method=\"GET\",code=\"200\"} 1000\n"
        . "http_requests_total{method=\"POST\",code=\"200\"} 200\n"
        . "# TYPE process_resident_memory_bytes gauge\n"
        . "process_resident_memory_bytes 51000000";

    $alertRows = '';
    foreach ($tsdb->evalRules($nowMs) as $name => $a) {
        $color = $a['state'] === 'firing' ? '#ff7b72' : ($a['state'] === 'pending' ? '#e3b341' : '#7ee787');
        $val = $a['value'] === null ? '—' : (string) round((float) $a['value'], 3);
        $alertRows .= "<tr><td>$name</td><td><code>" . htmlspecialchars($a['expr']) . "</code></td>"
            . "<td style='color:$color;font-weight:700'>{$a['state']}</td><td>$val</td></tr>";
    }

    $banner = $scraped !== null
        ? "<div class='box'><span class='label'>已寫入 " . (int) $scraped . " 個樣本（時間戳由 TSDB 拉取當下打上）</span></div>"
        : '';

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>指標監控系統 Prometheus · Demo</title>
<style>body{max-width:860px;margin:40px auto;padding:0 16px;background:#0d1117;color:#c9d1d9;
font-family:system-ui,"Microsoft JhengHei",sans-serif;line-height:1.6}
h1,h2{color:#e6edf3}a{color:#58a6ff}
.box{border-left:4px solid #7ee787;background:#161b22;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block}
code{background:#161b22;padding:2px 6px;border-radius:4px;color:#79c0ff}
table{width:100%;border-collapse:collapse;margin:.5em 0}
td,th{border:1px solid #30363d;padding:8px;text-align:left}th{background:#161b22}
textarea{width:100%;height:120px;background:#0d1117;color:#c9d1d9;border:1px solid #30363d;border-radius:6px;padding:8px;font-family:monospace}
input,button{background:#21262d;color:#c9d1d9;border:1px solid #30363d;border-radius:6px;padding:8px 12px}
button{cursor:pointer}button:hover{background:#30363d}
.muted{color:#8b949e;font-size:.85rem}</style>
</head><body>
<h1>📈 指標監控系統 Prometheus · Demo</h1>
<p>模擬 target 暴露 <code>/metrics</code> → TSDB 定時 scrape → series 識別 → PromQL 風格 <code>rate()</code>/<code>sum by()</code> 查詢 → 告警狀態機。</p>
$banner

<h2>1. 模擬 scrape（餵曝露格式樣本）</h2>
<p class="muted">每次「scrape」TSDB 會以「拉取當下」的毫秒時間戳寫入。多按幾次（counter 值請改大）即可形成時間窗，讓 rate() 有資料可算。</p>
<form method="post" action="/api/scrape">
  <textarea name="metrics">$sampleText</textarea>
  <button type="submit">▶ Scrape 寫入</button>
</form>

<h2>2. 目前 series（name + labels 唯一）</h2>
<table><tr><th>series key</th><th>型別</th><th>樣本數</th><th>最新值</th></tr>$seriesRows</table>
<p class="muted">曝露格式輸出：<a href="/metrics" target="_blank">/metrics</a></p>

<h2>3. 查詢（instant / rate / sum）</h2>
<ul class="muted">
  <li><a href="/api/query?expr=instant%20process_resident_memory_bytes" target="_blank">instant process_resident_memory_bytes</a></li>
  <li><a href="/api/query?expr=rate%20http_requests_total%5B60%5D" target="_blank">rate http_requests_total[60]</a>（需至少兩次不同值的 scrape）</li>
  <li><a href="/api/query?expr=sum%20http_requests_total" target="_blank">sum http_requests_total</a></li>
  <li><a href="/api/query?expr=sum%20http_requests_total%20by(method)" target="_blank">sum http_requests_total by(method)</a></li>
</ul>

<h2>4. 告警狀態（狀態機 pending→firing→resolved）</h2>
<table><tr><th>告警</th><th>expr</th><th>狀態</th><th>當前值</th></tr>$alertRows</table>
<p class="muted">即時評估：<a href="/api/alerts" target="_blank">/api/alerts</a>　預設規則 <code>rate http_requests_total[60] &gt; 5</code>。</p>

<p class="muted">※ 單機 JSON 模擬 TSDB；series 識別 / scrape / rate（含 counter reset）/ sum / 告警狀態機為真實邏輯，未做壓縮與高基數最佳化。</p>
</body></html>
HTML;
}
