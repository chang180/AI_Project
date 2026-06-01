<?php
declare(strict_types=1);

/**
 * 日誌聚合（Log Aggregation, ELK）Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8045 -t public
 *
 * 路由：
 *   GET  /                  測試頁（餵 log、用 關鍵字+等級+時間 查、看命中）
 *   POST /api/logs          批次攝取：application/json { "logs":[{ts,level,service,message}] }
 *                           或測試頁表單（單筆）
 *   GET  /api/search?q=&level=&from=&to=   倒排索引查詢（關鍵字 AND + 等級 + 時間範圍）
 */

require __DIR__ . '/../src/LogIndex.php';

$index = new LogIndex(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- POST /api/logs：批次攝取 ----
if ($method === 'POST' && $path === '/api/logs') {
    $raw = (string) file_get_contents('php://input');
    $nowMs = now_ms();
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

    // (a) 測試頁單筆表單
    if (isset($_POST['message'])) {
        $id = $index->ingest(
            (string) ($_POST['level'] ?? 'info'),
            (string) ($_POST['service'] ?? 'unknown'),
            (string) $_POST['message'],
            $nowMs
        );
        header('Location: /?ingested=1&last=' . $id);
        exit;
    }

    // (b) JSON 批次
    if (str_contains($contentType, 'application/json') || $raw !== '') {
        $body = json_decode($raw, true);
        $logs = is_array($body['logs'] ?? null) ? $body['logs'] : [];
        $ids = $index->ingestBatch($logs, $nowMs);
        exit(json_out(['ingested' => count($ids), 'ids' => $ids]));
    }

    exit(json_out(['ingested' => 0, 'ids' => []]));
}

// ---- GET /api/search：查詢 ----
if ($method === 'GET' && $path === '/api/search') {
    $q = (string) ($_GET['q'] ?? '');
    $level = isset($_GET['level']) && $_GET['level'] !== '' ? (string) $_GET['level'] : null;
    $from = isset($_GET['from']) && $_GET['from'] !== '' ? (int) $_GET['from'] : null;
    $to = isset($_GET['to']) && $_GET['to'] !== '' ? (int) $_GET['to'] : null;
    $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 50;
    exit(json_out($index->search($q, $level, $from, $to, $limit)));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($index);
    exit;
}

http_response_code(404);
echo 'not found';

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

function render_home(LogIndex $index): void
{
    $ingested = $_GET['ingested'] ?? null;
    $nowMs = now_ms();

    // 查詢條件（GET 帶回，方便測試頁直接查）
    $q = htmlspecialchars((string) ($_GET['q'] ?? ''));
    $levelSel = (string) ($_GET['level'] ?? '');

    // 統計
    $docCount = $index->docCount();
    $termCount = $index->termCount();
    $levels = $index->levelCounts();

    // 倒排索引前幾名詞
    $termRows = '';
    foreach ($index->topTerms(12) as $t) {
        $termRows .= '<tr><td><code>' . htmlspecialchars($t['term']) . '</code></td><td>'
            . (int) $t['count'] . '</td></tr>';
    }
    if ($termRows === '') {
        $termRows = "<tr><td colspan='2' style='color:#888'>尚無索引，請先攝取 log</td></tr>";
    }

    // 執行一次查詢（若有任何查詢參數）
    $resultBlock = '';
    if (isset($_GET['q']) || isset($_GET['level'])) {
        $r = $index->search(
            (string) ($_GET['q'] ?? ''),
            $levelSel !== '' ? $levelSel : null,
            isset($_GET['from']) && $_GET['from'] !== '' ? (int) $_GET['from'] : null,
            isset($_GET['to']) && $_GET['to'] !== '' ? (int) $_GET['to'] : null,
            50
        );
        $rows = '';
        foreach ($r['hits'] as $h) {
            $color = level_color((string) $h['level']);
            $time = date('H:i:s', (int) ((int) $h['ts'] / 1000));
            $rows .= "<tr><td class='muted'>{$time}</td>"
                . "<td style='color:$color;font-weight:700'>" . htmlspecialchars((string) $h['level']) . '</td>'
                . '<td><code>' . htmlspecialchars((string) $h['service']) . '</code></td>'
                . '<td>' . htmlspecialchars((string) $h['message']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='4' style='color:#888'>無命中</td></tr>";
        }
        $usedIndex = $r['usedIndex'] ? '是（倒排索引取候選）' : '否（全文件掃描）';
        $cand = (int) ($r['stats']['candidates'] ?? 0);
        $resultBlock = "<h2>查詢結果（命中 {$r['total']} 筆，顯示 {$r['returned']}）</h2>"
            . "<p class='muted'>使用倒排索引：{$usedIndex}；候選 id 數：{$cand}（先用索引縮小範圍，再套等級/時間過濾）</p>"
            . '<table><tr><th>時間</th><th>等級</th><th>服務</th><th>訊息</th></tr>' . $rows . '</table>';
    }

    $banner = $ingested !== null
        ? "<div class='box'><span class='label'>已攝取 log（id=" . htmlspecialchars((string) ($_GET['last'] ?? '')) . "），訊息已 tokenize 進倒排索引</span></div>"
        : '';

    // 等級下拉選項
    $opt = static function (string $v, string $label, string $sel): string {
        $s = $v === $sel ? ' selected' : '';
        return "<option value=\"$v\"$s>$label</option>";
    };
    $levelOpts = $opt('', '（不限等級）', $levelSel)
        . $opt('debug', 'debug', $levelSel)
        . $opt('info', 'info', $levelSel)
        . $opt('warn', 'warn', $levelSel)
        . $opt('error', 'error', $levelSel);

    $sampleJson = htmlspecialchars(
        '{"logs":[' . "\n"
        . '  {"level":"info","service":"auth-service","message":"user login success uid=42"},' . "\n"
        . '  {"level":"error","service":"auth-service","message":"login failed invalid password uid=99"},' . "\n"
        . '  {"level":"warn","service":"api-gateway","message":"upstream timeout GET /api/orders"}' . "\n"
        . ']}'
    );

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>日誌聚合 ELK · Demo</title>
<style>body{max-width:900px;margin:40px auto;padding:0 16px;background:#0d1117;color:#c9d1d9;
font-family:system-ui,"Microsoft JhengHei",sans-serif;line-height:1.6}
h1,h2{color:#e6edf3}a{color:#58a6ff}
.box{border-left:4px solid #7ee787;background:#161b22;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block}
code{background:#161b22;padding:2px 6px;border-radius:4px;color:#79c0ff}
table{width:100%;border-collapse:collapse;margin:.5em 0}
td,th{border:1px solid #30363d;padding:8px;text-align:left;vertical-align:top}th{background:#161b22}
textarea{width:100%;height:120px;background:#0d1117;color:#c9d1d9;border:1px solid #30363d;border-radius:6px;padding:8px;font-family:monospace}
input,button,select{background:#21262d;color:#c9d1d9;border:1px solid #30363d;border-radius:6px;padding:8px 12px}
button{cursor:pointer}button:hover{background:#30363d}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:.5em 0}
.muted{color:#8b949e;font-size:.85rem}
.stats{display:flex;gap:18px;flex-wrap:wrap}
.stats div{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:10px 16px}
.stats b{color:#e6edf3;font-size:1.3em;display:block}</style>
</head><body>
<h1>🔎 日誌聚合 ELK · Demo</h1>
<p>應用送 log → <strong>攝取（ingest）</strong> → tokenize → <strong>倒排索引</strong>（詞 → 哪幾筆 log）→
<strong>查詢</strong>（關鍵字 AND + 等級過濾 + 時間範圍）。對應 ELK 的 Logstash 攝取 + Elasticsearch 倒排索引 + Kibana 查詢。</p>
$banner

<div class="stats">
  <div><b>$docCount</b><span class="muted">log 文件數</span></div>
  <div><b>$termCount</b><span class="muted">倒排索引詞數</span></div>
  <div><b>{$levels['error']}</b><span class="muted">error</span></div>
  <div><b>{$levels['warn']}</b><span class="muted">warn</span></div>
  <div><b>{$levels['info']}</b><span class="muted">info</span></div>
</div>

<h2>1. 攝取一筆 log（結構化欄位）</h2>
<form method="post" action="/api/logs">
  <div class="row">
    <select name="level">
      <option value="info">info</option>
      <option value="debug">debug</option>
      <option value="warn">warn</option>
      <option value="error">error</option>
    </select>
    <input name="service" placeholder="service（如 auth-service）" value="auth-service" style="flex:1">
  </div>
  <div class="row">
    <input name="message" placeholder="日誌訊息（會被 tokenize 進倒排索引）" value="login failed invalid password uid=42" style="flex:1">
    <button type="submit">▶ 攝取</button>
  </div>
</form>
<p class="muted">批次攝取（JSON）：<code>POST /api/logs</code> body 範例：</p>
<pre><code>$sampleJson</code></pre>

<h2>2. 查詢（倒排索引：關鍵字 AND + 等級 + 時間範圍）</h2>
<form method="get" action="/">
  <div class="row">
    <input name="q" placeholder="關鍵字（多詞以空白分隔，取交集）" value="$q" style="flex:1">
    <select name="level">$levelOpts</select>
    <button type="submit">🔍 查詢</button>
  </div>
  <p class="muted">時間範圍可加 <code>from</code>/<code>to</code>（毫秒）：例如 <a href="/api/search?q=failed&level=error">/api/search?q=failed&level=error</a></p>
</form>
$resultBlock

<h2>3. 倒排索引內容（詞 → posting list 長度，前 12 名）</h2>
<table><tr><th>詞（token）</th><th>出現於幾筆 log</th></tr>$termRows</table>

<p class="muted">※ 單機 JSON 模擬。攝取管線、tokenize、倒排索引、關鍵字 AND 交集、等級+時間過濾為真實邏輯；
未做分片、時間分桶 rollover、保留分層、壓縮與分散式合併。
查詢 API：<a href="/api/search?q=&level=error">/api/search?q=&level=error</a></p>
</body></html>
HTML;
}

function level_color(string $level): string
{
    return match ($level) {
        'error' => '#ff7b72',
        'warn' => '#e3b341',
        'debug' => '#8b949e',
        default => '#7ee787',
    };
}
