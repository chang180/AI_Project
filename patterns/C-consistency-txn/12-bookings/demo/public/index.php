<?php
declare(strict_types=1);

/**
 * Bookings.com 短租平台 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8012 -t public
 *
 * 路由：
 *   GET  /                          測試頁（建房況、訂房、hold/confirm/release、看日曆）
 *   POST /api/rooms                 建立房源 + 日期庫存  { room_id, name, from, to }
 *   GET  /api/rooms/{id}/calendar   房況日曆（哪幾天 OPEN/HELD/BOOKED）
 *   POST /api/holds                 建立保留（防超賣核心）{ room_id, checkin, checkout, strategy }
 *   POST /api/holds/{id}/confirm    付款成功 → 確認（HELD→BOOKED）
 *   POST /api/holds/{id}/release    付款失敗/取消 → 釋放（HELD→OPEN）
 *   POST /api/sweep                 背景 TTL 掃描器：釋放逾期 hold
 */

require __DIR__ . '/../src/InventoryStore.php';
require __DIR__ . '/../src/BookingService.php';

$store = new InventoryStore(__DIR__ . '/../data');
$svc = new BookingService($store);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/rooms：建立房源 + 庫存日曆 ----
if ($method === 'POST' && $path === '/api/rooms') {
    $in = body_params();
    $roomId = trim((string)($in['room_id'] ?? ''));
    $name = trim((string)($in['name'] ?? $roomId));
    $from = trim((string)($in['from'] ?? ''));
    $to = trim((string)($in['to'] ?? ''));
    if ($roomId === '' || $from === '' || $to === '') {
        http_response_code(400);
        exit(json_out(['error' => '需要 room_id / from / to']));
    }
    $res = $svc->createRoom($roomId, $name, $from, $to);
    redirect_or_json($res);
}

// ---- POST /api/holds：建立保留（防超賣） ----
if ($method === 'POST' && $path === '/api/holds') {
    $in = body_params();
    $roomId = trim((string)($in['room_id'] ?? ''));
    $checkin = trim((string)($in['checkin'] ?? ''));
    $checkout = trim((string)($in['checkout'] ?? ''));
    $strategy = ($in['strategy'] ?? 'pessimistic') === 'optimistic' ? 'optimistic' : 'pessimistic';
    $res = $svc->hold($roomId, $checkin, $checkout, $strategy);
    http_response_code((int)($res['code'] ?? 200));
    redirect_or_json($res);
}

// ---- POST /api/holds/{id}/confirm ----
if ($method === 'POST' && preg_match('#^/api/holds/([A-Za-z0-9]+)/confirm$#', $path, $m)) {
    $res = $svc->confirm($m[1]);
    http_response_code((int)($res['code'] ?? 200));
    redirect_or_json($res);
}

// ---- POST /api/holds/{id}/release ----
if ($method === 'POST' && preg_match('#^/api/holds/([A-Za-z0-9]+)/release$#', $path, $m)) {
    $res = $svc->release($m[1]);
    http_response_code((int)($res['code'] ?? 200));
    redirect_or_json($res);
}

// ---- POST /api/sweep：背景 TTL 釋放 ----
if ($method === 'POST' && $path === '/api/sweep') {
    $res = $svc->sweepExpired();
    redirect_or_json($res);
}

// ---- GET /api/rooms/{id}/calendar ----
if ($method === 'GET' && preg_match('#^/api/rooms/([A-Za-z0-9_-]+)/calendar$#', $path, $m)) {
    $res = $svc->calendar($m[1]);
    if (!($res['ok'] ?? false)) { http_response_code(404); }
    exit(json_out($res));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($svc);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
function body_params(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return [];
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** 表單（text/html）送出 → 導回首頁帶訊息；API 呼叫 → 回 JSON */
function redirect_or_json(array $res): never
{
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        $msg = rawurlencode(json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        header('Location: /?msg=' . $msg);
        exit;
    }
    exit(json_out($res));
}

function render_home(BookingService $svc): void
{
    $msg = '';
    if (isset($_GET['msg'])) {
        $decoded = json_decode((string)$_GET['msg'], true);
        $pretty = htmlspecialchars(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $ok = ($decoded['ok'] ?? null) === true || isset($decoded['room_id']) || isset($decoded['released_holds']);
        $cls = $ok ? 'tip' : 'warn';
        $msg = "<div class='box $cls'><span class='label'>上一個操作結果</span><pre><code>$pretty</code></pre></div>";
    }

    // 房況日曆表
    $roomsHtml = '';
    foreach ($svc->allRooms() as $rid => $room) {
        $cells = '';
        foreach ($room['calendar'] as $date => $cell) {
            $color = match ($cell['status']) {
                'OPEN' => '#2f9e44',
                'HELD' => '#f0883e',
                'BOOKED' => '#ff7b72',
                default => '#888',
            };
            $cells .= "<span class='cal' style='border-color:$color;color:$color' title='{$cell['status']}'>"
                . substr($date, 5) . "<br><small>{$cell['status']}</small></span>";
        }
        $roomsHtml .= "<div class='roombox'><strong>$rid</strong> · " . htmlspecialchars($room['name'])
            . "<div class='calrow'>$cells</div></div>";
    }
    if ($roomsHtml === '') {
        $roomsHtml = "<p style='color:#9aa7b3'>尚無房源，先用上方表單建立。</p>";
    }

    // hold 清單
    $holdsHtml = '';
    foreach ($svc->allHolds() as $hid => $h) {
        $left = $h['expires_at'] - time();
        $leftTxt = $h['status'] === 'HELD' ? ($left > 0 ? "{$left}s 後逾時" : '已逾時') : '-';
        $holdsHtml .= "<tr><td><code>$hid</code></td><td>{$h['room_id']}</td>"
            . "<td>{$h['checkin']}→{$h['checkout']}</td><td>{$h['status']}</td><td>$leftTxt</td>"
            . "<td><form method='post' action='/api/holds/$hid/confirm' style='display:inline'><button>確認</button></form> "
            . "<form method='post' action='/api/holds/$hid/release' style='display:inline'><button>釋放</button></form></td></tr>";
    }
    if ($holdsHtml === '') {
        $holdsHtml = "<tr><td colspan='6' style='color:#9aa7b3'>尚無保留</td></tr>";
    }

    $ttl = BookingService::HOLD_TTL_SECONDS;

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bookings.com 短租平台 · Demo</title>
<style>
:root{--bg:#0f1419;--soft:#1a2027;--card:#1e2630;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--ok:#7ee787;--warn:#f0883e}
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.6;max-width:920px;margin:0 auto;padding:24px 18px 80px}
h1{font-size:1.6rem}h2{font-size:1.2rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin-top:1.6em}
a{color:var(--accent);text-decoration:none}
code{background:#161b22;padding:2px 6px;border-radius:6px;color:var(--ok);font-size:.88em}
pre{background:#161b22;border:1px solid var(--border);border-radius:10px;padding:12px;overflow-x:auto;font-size:.82rem}
pre code{background:none;color:var(--text)}
.box{border-left:4px solid var(--accent);background:var(--soft);padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box.tip{border-color:var(--ok)}.box.warn{border-color:var(--warn)}.box .label{font-weight:700;display:block;margin-bottom:.2em}
.card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px 18px;margin:12px 0}
input,select,button{font-size:.9rem;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:#0f1419;color:var(--text)}
button{background:var(--accent);color:#06121f;border:none;cursor:pointer;font-weight:600}
button:hover{opacity:.9}
table{width:100%;border-collapse:collapse;font-size:.88rem;margin-top:.5em}
th,td{border:1px solid var(--border);padding:8px;text-align:left}th{background:var(--soft);color:var(--accent)}
.roombox{margin:10px 0;padding:10px;border:1px solid var(--border);border-radius:8px;background:var(--soft)}
.calrow{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.cal{display:inline-block;border:1.5px solid;border-radius:6px;padding:4px 6px;font-size:.72rem;text-align:center;min-width:46px}
label{display:inline-block;color:var(--dim);font-size:.82rem;margin:4px 8px 4px 0}
.legend span{margin-right:14px;font-size:.8rem}
</style></head><body>
<h1>🏨 Bookings.com 短租平台 · Demo</h1>
<p style="color:var(--dim)">聚焦 <strong>並發防超賣 + 區間原子扣減 + hold/TTL</strong>。庫存單位＝每房每日一格。退房日不佔。HOLD TTL = {$ttl}s。</p>
$msg

<div class="card">
  <h2 style="margin-top:0;border:none">① 建立房源 + 庫存日曆</h2>
  <form method="post" action="/api/rooms">
    <label>房號 <input name="room_id" value="R1" required></label>
    <label>名稱 <input name="name" value="海景套房"></label>
    <label>起 <input name="from" value="2026-06-01" required></label>
    <label>迄(含) <input name="to" value="2026-06-07" required></label>
    <button>建立</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;border:none">② 訂房（建立保留 hold）</h2>
  <p style="color:var(--dim);font-size:.82rem">同一房同日期區間若被搶先佔用，整筆回 <code>409 部分日期已被預訂</code>（防超賣）。</p>
  <form method="post" action="/api/holds">
    <label>房號 <input name="room_id" value="R1" required></label>
    <label>入住 <input name="checkin" value="2026-06-01" required></label>
    <label>退房 <input name="checkout" value="2026-06-04" required></label>
    <label>並發控制 <select name="strategy"><option value="pessimistic">悲觀鎖 FOR UPDATE</option><option value="optimistic">樂觀鎖 CAS</option></select></label>
    <button>下單保留</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;border:none">③ 房況日曆</h2>
  <p class="legend"><span style="color:#2f9e44">■ OPEN 可訂</span><span style="color:#f0883e">■ HELD 保留中</span><span style="color:#ff7b72">■ BOOKED 已訂</span></p>
  $roomsHtml
</div>

<div class="card">
  <h2 style="margin-top:0;border:none">④ 保留 / 訂單</h2>
  <form method="post" action="/api/sweep" style="margin-bottom:8px"><button>▶ 執行 TTL 掃描器（釋放逾期 hold）</button></form>
  <table><tr><th>hold</th><th>房</th><th>區間</th><th>狀態</th><th>TTL</th><th>操作</th></tr>$holdsHtml</table>
</div>

<div class="box warn"><span class="label">誠實聲明</span>
本 demo 用<strong>檔案鎖 flock 模擬 DB 交易與行鎖</strong>、JSON 檔模擬庫存日曆表。並發防超賣、區間原子扣減、hold/TTL、樂觀鎖條件更新等<strong>系統邏輯為真實可執行</strong>；真實系統改用 DB 交易（BEGIN…FOR UPDATE / UPDATE…WHERE status='OPEN'…COMMIT），演算法一致。
</div>
</body></html>
HTML;
}
