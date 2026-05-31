<?php
declare(strict_types=1);

/**
 * Polymarket 預測市場 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8003 -t public
 *
 * 路由：
 *   GET  /                    測試頁（委託簿、最近成交、帳戶餘額/持倉、下單表單）
 *   POST /api/order           下單 { user, side(buy|sell), price, qty }
 *   POST /api/resolve         市場到期結算 { winner: YES|NO }
 *   POST /api/reset           重置 demo 資料
 *   GET  /api/state           回傳完整狀態 JSON（委託簿/成交/帳戶/事件）
 *
 * 聚焦：order book 撮合（價格-時間優先、部分成交）+ 資金一致性（凍結/原子過帳/防超額）。
 */

require __DIR__ . '/../src/EventLog.php';
require __DIR__ . '/../src/Ledger.php';
require __DIR__ . '/../src/OrderBook.php';
require __DIR__ . '/../src/MatchingEngine.php';

$dataDir = __DIR__ . '/../data';
$log     = new EventLog($dataDir);
$ledger  = new Ledger($dataDir);
$engine  = new MatchingEngine($dataDir, $ledger, $log);

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($path, '/') ?: '/';

// ---- POST /api/order：下單（撮合核心入口）----
if ($method === 'POST' && $path === '/api/order') {
    $in     = read_input();
    $user   = trim((string) ($in['user'] ?? ''));
    $side   = trim((string) ($in['side'] ?? ''));
    $price  = (float) ($in['price'] ?? 0);
    $qty    = (float) ($in['qty'] ?? 0);
    if ($user === '') {
        http_response_code(400);
        exit(json_out(['ok' => false, 'reason' => '缺少 user']));
    }
    $res = $engine->submit($user, $side, $price, $qty);
    if (wants_html()) { header('Location: /'); exit; }
    if (!$res['ok']) http_response_code(409);
    exit(json_out($res));
}

// ---- POST /api/resolve：市場結算 ----
if ($method === 'POST' && $path === '/api/resolve') {
    $in     = read_input();
    $winner = strtoupper(trim((string) ($in['winner'] ?? 'YES')));
    if ($winner !== 'YES' && $winner !== 'NO') $winner = 'YES';
    $res = $engine->resolve($winner);
    if (wants_html()) { header('Location: /'); exit; }
    exit(json_out($res));
}

// ---- POST /api/reset：重置資料 ----
if ($method === 'POST' && $path === '/api/reset') {
    foreach (['events.log.json', 'ledger.json', 'book.json', 'trades.json'] as $f) {
        @unlink($dataDir . '/' . $f);
    }
    if (wants_html()) { header('Location: /'); exit; }
    exit(json_out(['ok' => true, 'reset' => true]));
}

// ---- GET /api/state：狀態 JSON ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out([
        'book'     => $engine->book(),
        'trades'   => $engine->recentTrades(),
        'accounts' => $ledger->accounts(),
        'events'   => $log->tail(30),
    ]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($engine, $ledger, $log);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
function read_input(): array
{
    if (!empty($_POST)) return $_POST;
    $raw = (string) file_get_contents('php://input');
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) return $j;
    }
    return [];
}

function wants_html(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function fmt(float $n): string
{
    return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.') ?: '0';
}

function render_home(MatchingEngine $engine, Ledger $ledger, EventLog $log): void
{
    $book   = $engine->book();
    $trades = $engine->recentTrades(12);
    $accts  = $ledger->accounts();
    $events = $log->tail(12);

    // 委託簿：賣單（價低在前）+ 買單（價高在前）
    $askRows = '';
    foreach (array_reverse($book['asks']) as $a) {
        $askRows .= "<tr class='ask'><td>賣 ASK</td><td>" . fmt((float)$a['price']) . "</td><td>" . fmt((float)$a['qty']) . "</td><td>" . htmlspecialchars((string)$a['user']) . "</td></tr>";
    }
    $bidRows = '';
    foreach ($book['bids'] as $b) {
        $bidRows .= "<tr class='bid'><td>買 BID</td><td>" . fmt((float)$b['price']) . "</td><td>" . fmt((float)$b['qty']) . "</td><td>" . htmlspecialchars((string)$b['user']) . "</td></tr>";
    }
    $bookRows = $askRows . $bidRows ?: "<tr><td colspan='4' style='color:#888'>（委託簿空）</td></tr>";

    $tradeRows = '';
    foreach (array_reverse($trades) as $t) {
        $tradeRows .= "<tr><td>" . fmt((float)$t['price']) . "</td><td>" . fmt((float)$t['qty']) . "</td><td>"
            . htmlspecialchars((string)$t['buyer']) . " ← " . htmlspecialchars((string)$t['seller']) . "</td></tr>";
    }
    $tradeRows = $tradeRows ?: "<tr><td colspan='3' style='color:#888'>（尚無成交）</td></tr>";

    $acctRows = '';
    foreach ($accts as $u => $a) {
        $acctRows .= "<tr><td><code>" . htmlspecialchars((string)$u) . "</code></td>"
            . "<td>" . fmt((float)($a['available'] ?? 0)) . "</td>"
            . "<td>" . fmt((float)($a['held'] ?? 0)) . "</td>"
            . "<td>" . fmt((float)($a['YES'] ?? 0)) . "</td>"
            . "<td>" . fmt((float)($a['NO'] ?? 0)) . "</td></tr>";
    }

    $evRows = '';
    foreach (array_reverse($events) as $e) {
        $evRows .= "<tr><td>{$e['seq']}</td><td><code>" . htmlspecialchars((string)$e['type']) . "</code></td>"
            . "<td style='font-size:.8rem;color:#9aa7b3'>" . htmlspecialchars(json_encode($e['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "</td></tr>";
    }
    $evRows = $evRows ?: "<tr><td colspan='3' style='color:#888'>（尚無事件）</td></tr>";

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Polymarket 預測市場 · Demo</title>
<style>
:root{--bg:#0f1419;--card:#1e2630;--soft:#1a2027;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--green:#7ee787;--warn:#f0883e;--danger:#ff7b72}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.6;max-width:1000px;margin:0 auto;padding:24px 16px 80px}
h1{font-size:1.6rem}h2{font-size:1.1rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin-top:1.6em}
a{color:var(--accent);text-decoration:none}
code{background:#161b22;color:var(--green);padding:2px 6px;border-radius:6px;font-size:.85em}
table{width:100%;border-collapse:collapse;margin:.6em 0;font-size:.9rem}
th,td{border:1px solid var(--border);padding:7px 10px;text-align:left}
th{background:var(--soft);color:var(--accent)}
.cols{display:flex;gap:18px;flex-wrap:wrap}.cols>div{flex:1;min-width:280px}
tr.ask td{color:var(--danger)}tr.bid td{color:var(--green)}
form{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin:.8em 0}
input,select,button{background:var(--soft);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:6px 10px;font-size:.9rem}
button{cursor:pointer;border-color:var(--accent);color:var(--accent)}
button:hover{background:var(--accent);color:#0f1419}
.box{border-left:4px solid var(--warn);background:var(--soft);padding:10px 14px;border-radius:0 8px 8px 0;margin:1em 0;font-size:.85rem}
.label{font-weight:700;display:block;margin-bottom:.2em;color:var(--warn)}
.muted{color:var(--dim);font-size:.85rem}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
</style></head><body>
<h1>🎲 Polymarket 預測市場 · Demo</h1>
<p class="muted">YES 股份，價格 0~1 = 市場機率。下單先<strong>凍結資金/股份</strong>，撮合採<strong>價格-時間優先</strong>，成交<strong>原子過帳</strong>，全程寫入<strong>事件日誌</strong>可重放。</p>

<form method="post" action="/api/order">
  <div class="row">
    <select name="user"><option>alice</option><option>bob</option></select>
    <select name="side"><option value="buy">買 YES (bid)</option><option value="sell">賣 YES (ask)</option></select>
    價 <input name="price" type="number" step="0.01" min="0.01" max="0.99" value="0.50" style="width:90px">
    量 <input name="qty" type="number" step="1" min="1" value="10" style="width:90px">
    <button type="submit">送出限價單</button>
  </div>
</form>
<form method="post" action="/api/resolve" style="display:inline-block">
  市場結算 → <select name="winner"><option>YES</option><option>NO</option></select>
  <button type="submit">結算</button>
</form>
<form method="post" action="/api/reset" style="display:inline-block">
  <button type="submit">重置 demo</button>
</form>

<div class="cols">
  <div>
    <h2>📖 委託簿（賣在上 / 買在下）</h2>
    <table><tr><th>側</th><th>價(機率)</th><th>量</th><th>掛單者</th></tr>$bookRows</table>
  </div>
  <div>
    <h2>💱 最近成交</h2>
    <table><tr><th>成交價</th><th>量</th><th>買方 ← 賣方</th></tr>$tradeRows</table>
  </div>
</div>

<h2>💰 帳戶（餘額 / 凍結 / 持倉）</h2>
<table><tr><th>使用者</th><th>可用現金</th><th>凍結現金</th><th>YES 持倉</th><th>NO 持倉</th></tr>$acctRows</table>

<h2>📜 事件日誌（append-only，可重放）</h2>
<table><tr><th>seq</th><th>type</th><th>data</th></tr>$evRows</table>

<div class="box">
  <span class="label">⚠️ 誠實聲明</span>
  本 demo 用 JSON 檔 + 檔案鎖模擬「DB 交易 / 單執行緒序列化」；撮合演算法、價格-時間優先、部分成交、
  凍結保證金、成交原子過帳、防超額/防雙花、事件溯源等<strong>系統邏輯為真實可執行</strong>。
  生產級需換成關聯式 DB 交易（條件 UPDATE / SELECT FOR UPDATE）、in-memory 撮合核心與 Kafka commit log。
</div>
</body></html>
HTML;
}
