<?php
declare(strict_types=1);

/**
 * 外送平台 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8057 -t public
 *
 * 三邊市場：食客（下單）/ 餐廳（接單備餐）/ 外送員（上線報位置、取送）。
 * 路由：
 *   GET  /                          測試頁（下單 / 外送員上線報位置 / 派單 / 推進狀態 / 追蹤）
 *   POST /api/order                 食客下單 { eater_id, restaurant, rest_lat, rest_lng, dest_lat, dest_lng }
 *   POST /api/courier/location      外送員上線 / 更新位置（模擬 GPS 串流）{ id, lat, lng, name? }
 *   POST /api/dispatch              派單給最近可用外送員（地理媒合 + CAS 原子指派）{ order_id }
 *   POST /api/order/advance         推進訂單狀態（狀態機）{ order_id }
 *   GET  /api/track/{id}            看訂單追蹤 + 即時 ETA
 */

require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/GeoIndex.php';
require __DIR__ . '/../src/Dispatcher.php';

$store      = new Store(__DIR__ . '/../data');
$geo        = new GeoIndex($store);
$dispatcher = new Dispatcher($store, $geo);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/order：食客下單 ----
if ($method === 'POST' && $path === '/api/order') {
    $in = body_input();
    $eater = trim((string)($in['eater_id'] ?? 'guest')) ?: 'guest';
    $rest  = trim((string)($in['restaurant'] ?? '')) ?: '某餐廳';
    $rLat = num($in['rest_lat'] ?? null);
    $rLng = num($in['rest_lng'] ?? null);
    $dLat = num($in['dest_lat'] ?? null);
    $dLng = num($in['dest_lng'] ?? null);
    if ($rLat === null || $rLng === null || $dLat === null || $dLng === null) {
        http_response_code(400);
        exit(json_out(['error' => '需要 rest_lat / rest_lng / dest_lat / dest_lng']));
    }
    $order = $dispatcher->placeOrder($eater, $rest, $rLat, $rLng, $dLat, $dLng);
    if (wants_html()) { header('Location: /?order=' . urlencode($order['order_id']) . '&msg=' . urlencode("已下單 {$order['order_id']}（狀態 placed）")); exit; }
    http_response_code(201);
    exit(json_out($order));
}

// ---- POST /api/courier/location：外送員上線 / 報位置（GPS 串流示意）----
if ($method === 'POST' && $path === '/api/courier/location') {
    $in = body_input();
    $id   = trim((string)($in['id'] ?? ''));
    $lat  = num($in['lat'] ?? null);
    $lng  = num($in['lng'] ?? null);
    $name = trim((string)($in['name'] ?? ''));
    if ($id === '' || $lat === null || $lng === null) {
        http_response_code(400);
        exit(json_out(['error' => '需要 id / lat / lng']));
    }
    // 已存在則只更新位置（不重設狀態）；不存在則上線（available）
    if ($store->getCourier($id)) {
        $store->updateLocation($id, $lat, $lng);
        $c = $store->getCourier($id);
    } else {
        $c = $store->upsertCourier($id, $lat, $lng, $name);
    }
    if (wants_html()) { header('Location: /?msg=' . urlencode("外送員 $id 已上線 / 報位置")); exit; }
    exit(json_out($c));
}

// ---- POST /api/dispatch：派單給最近可用外送員（地理媒合 + 原子指派）----
if ($method === 'POST' && $path === '/api/dispatch') {
    $in = body_input();
    $orderId = trim((string)($in['order_id'] ?? ''));
    if ($orderId === '') {
        http_response_code(400);
        exit(json_out(['error' => '需要 order_id']));
    }
    $result = $dispatcher->dispatch($orderId);
    if (!$result) {
        if (wants_html()) { header('Location: /?order=' . urlencode($orderId) . '&msg=' . urlencode('派單失敗：附近無可用外送員，或訂單尚未被餐廳接受 / 已派過')); exit; }
        http_response_code(409);
        exit(json_out(['error' => '附近無可用外送員，或訂單未到可派狀態（需 accepted/preparing 且未派過）']));
    }
    if (wants_html()) { header('Location: /?order=' . urlencode($orderId) . '&msg=' . urlencode("已派給 {$result['courier']['courier_id']}（距餐廳 {$result['distance_km']} km, ETA {$result['order']['eta_min']} 分）")); exit; }
    exit(json_out($result));
}

// ---- POST /api/order/advance：推進訂單狀態（狀態機）----
if ($method === 'POST' && $path === '/api/order/advance') {
    $in = body_input();
    $orderId = trim((string)($in['order_id'] ?? ''));
    if ($orderId === '') {
        http_response_code(400);
        exit(json_out(['error' => '需要 order_id']));
    }
    $r = $dispatcher->advance($orderId);
    if (!$r['ok']) {
        if (wants_html()) { header('Location: /?order=' . urlencode($orderId) . '&msg=' . urlencode('推進失敗：' . $r['error'])); exit; }
        http_response_code(409);
        exit(json_out(['error' => $r['error']]));
    }
    if (wants_html()) { header('Location: /?order=' . urlencode($orderId) . '&msg=' . urlencode("訂單推進至 {$r['order']['status']}")); exit; }
    exit(json_out($r['order']));
}

// ---- GET /api/track/{id}：訂單追蹤 + 即時 ETA ----
if ($method === 'GET' && preg_match('#^/api/track/([A-Za-z0-9_]+)$#', $path, $m)) {
    $t = $dispatcher->track($m[1]);
    if (!$t) {
        http_response_code(404);
        exit(json_out(['error' => '訂單不存在']));
    }
    exit(json_out($t));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($store, $dispatcher);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
function num(mixed $v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    return (float)$v;
}

function body_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = (string) file_get_contents('php://input');
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
        parse_str($raw, $parsed);
        if ($parsed) {
            return $parsed;
        }
    }
    return [];
}

function wants_html(): bool
{
    return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function render_home(Store $store, Dispatcher $dispatcher): void
{
    $msg     = isset($_GET['msg']) ? htmlspecialchars((string)$_GET['msg']) : '';
    $focusId = isset($_GET['order']) ? (string)$_GET['order'] : null;

    $banner = '';
    if ($msg !== '') {
        $banner = "<div class='box tip'><span class='label'>訊息</span>$msg</div>";
    }

    // 追蹤面板（聚焦的訂單）
    if ($focusId) {
        $t = $dispatcher->track($focusId);
        if ($t) {
            $o = $t['order'];
            $cn = $o['courier_id'] ?? '—';
            $cloc = $t['courier'] ? "{$t['courier']['lat']}, {$t['courier']['lng']}" : '—';
            $eta = $t['live_eta_min'] !== null ? "{$t['live_eta_min']} 分" : '—';
            $nxt = $t['next_status'] ? "<form method='post' action='/api/order/advance' style='display:inline'><input type='hidden' name='order_id' value='" . htmlspecialchars($focusId) . "'><button type='submit'>推進到 {$t['next_status']}</button></form>" : '<em>已送達（終態）</em>';
            $dsp = ($o['courier_id'] ?? null) === null ? "<form method='post' action='/api/dispatch' style='display:inline'><input type='hidden' name='order_id' value='" . htmlspecialchars($focusId) . "'><button type='submit'>派最近外送員</button></form>" : '';
            $banner .= "<div class='box tip'><span class='label'>追蹤 {$o['order_id']}</span>"
                . "餐廳 <code>" . htmlspecialchars((string)$o['restaurant']) . "</code> · "
                . "狀態 <strong>{$o['status']}</strong> · 外送員 <code>" . htmlspecialchars((string)$cn) . "</code>（位置 $cloc）· "
                . "即時 ETA <strong>$eta</strong><br>$dsp $nxt</div>";
        }
    }

    // 外送員表
    $crows = '';
    foreach ($store->allCouriers() as $id => $c) {
        $color = $c['status'] === 'available' ? '#7ee787' : ($c['status'] === 'assigned' ? '#f0883e' : '#9aa7b3');
        $crows .= "<tr><td><code>" . htmlspecialchars((string)$id) . "</code></td>"
            . "<td>" . htmlspecialchars((string)$c['name']) . "</td>"
            . "<td>{$c['lat']}, {$c['lng']}</td>"
            . "<td style='color:$color;font-weight:700'>{$c['status']}</td>"
            . "<td><code>" . htmlspecialchars((string)($c['order_id'] ?? '—')) . "</code></td>"
            . "<td><form method='post' action='/api/courier/location' style='display:flex;gap:4px'>"
            . "<input type='hidden' name='id' value='" . htmlspecialchars((string)$id) . "'>"
            . "<input name='lat' value='{$c['lat']}' style='width:90px'>"
            . "<input name='lng' value='{$c['lng']}' style='width:90px'>"
            . "<button type='submit'>更新位置</button></form></td></tr>";
    }
    $crows = $crows ?: "<tr><td colspan='6' style='color:#9aa7b3'>尚無外送員，先在右側讓外送員上線報位置</td></tr>";

    // 訂單表
    $orows = '';
    foreach (array_reverse($store->allOrders(), true) as $oid => $o) {
        $link = "/?order=" . urlencode((string)$oid);
        $orows .= "<tr><td><a href='$link'><code>" . htmlspecialchars((string)$oid) . "</code></a></td>"
            . "<td>" . htmlspecialchars((string)$o['restaurant']) . "</td>"
            . "<td>{$o['eater_id']}</td>"
            . "<td><strong>{$o['status']}</strong></td>"
            . "<td><code>" . htmlspecialchars((string)($o['courier_id'] ?? '—')) . "</code></td>"
            . "<td>" . ($o['eta_min'] !== null ? "{$o['eta_min']} 分" : '—') . "</td>"
            . "<td>{$o['distance_km']} km</td></tr>";
    }
    $orows = $orows ?: "<tr><td colspan='7' style='color:#9aa7b3'>尚無訂單</td></tr>";

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>外送平台 · Demo</title>
<style>
  :root{--bg:#0f1419;--soft:#1a2027;--card:#1e2630;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--accent2:#7ee787;--warn:#f0883e}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.6}
  .wrap{max-width:1080px;margin:0 auto;padding:28px 20px 80px}
  h1{font-size:1.7rem;margin:.2em 0}
  h2{font-size:1.2rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin-top:1.6em}
  a{color:var(--accent);text-decoration:none}
  code{background:#161b22;color:var(--accent2);padding:2px 6px;border-radius:6px;font-size:.88em}
  table{width:100%;border-collapse:collapse;margin:1em 0;font-size:.9rem}
  th,td{border:1px solid var(--border);padding:8px 10px;text-align:left;vertical-align:middle}
  th{background:var(--soft);color:var(--accent)}
  .box{border-left:4px solid var(--accent);background:var(--soft);padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
  .box.tip{border-color:var(--accent2)}
  .box .label{font-weight:700;display:block;margin-bottom:.2em}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .panel{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px}
  input,button{background:#161b22;color:var(--text);border:1px solid var(--border);border-radius:6px;padding:6px 10px;font-size:.9rem}
  button{background:var(--accent);color:#0b0f14;border-color:var(--accent);font-weight:700;cursor:pointer}
  button:hover{filter:brightness(1.1)}
  form.block{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
  .hint{color:var(--dim);font-size:.85rem}
  .flow{color:var(--dim);font-size:.85rem;margin:.4em 0}
  .flow code{color:var(--warn)}
</style></head><body>
<div class="wrap">
<h1>🛵 外送平台 · Demo</h1>
<p class="hint">三邊市場：<strong>食客下單</strong> / <strong>餐廳接單備餐</strong> / <strong>外送員取送</strong>。聚焦 <strong>geohash 地理派單 + CAS 原子指派（防重複） + 訂單狀態機 + 即時 ETA</strong>。座標可用台北市中心附近：25.04 / 121.56。</p>
<p class="flow">生命週期：<code>placed</code> → <code>accepted</code> → <code>preparing</code> →（派單後）→ <code>picked_up</code> → <code>delivered</code>。在「準備中(preparing)」前須先<strong>派外送員</strong>才能取餐。</p>
$banner

<div class="grid">
  <div class="panel">
    <h2 style="margin-top:0">① 食客下單</h2>
    <form class="block" method="post" action="/api/order">
      <input name="eater_id" placeholder="食客 id" value="u1" style="width:90px">
      <input name="restaurant" placeholder="餐廳名" value="鼎泰豐" style="width:110px">
      <input name="rest_lat" placeholder="餐廳 lat" value="25.0418" required style="width:100px">
      <input name="rest_lng" placeholder="餐廳 lng" value="121.5654" required style="width:100px">
      <input name="dest_lat" placeholder="送達 lat" value="25.0480" required style="width:100px">
      <input name="dest_lng" placeholder="送達 lng" value="121.5700" required style="width:100px">
      <button type="submit">下單</button>
    </form>
    <p class="hint">下單後狀態為 <code>placed</code>，點訂單編號可進追蹤面板，依序推進狀態。</p>
  </div>
  <div class="panel">
    <h2 style="margin-top:0">② 外送員上線 / 報位置</h2>
    <form class="block" method="post" action="/api/courier/location">
      <input name="id" placeholder="外送員 id 如 c1" required style="width:90px">
      <input name="name" placeholder="名字(可空)" style="width:90px">
      <input name="lat" placeholder="lat 25.042" required style="width:100px">
      <input name="lng" placeholder="lng 121.565" required style="width:100px">
      <button type="submit">上線 / 報位置</button>
    </form>
    <p class="hint">讓幾個外送員在不同座標上線，模擬路上的外送員；送單中報位置不會被重設為可用。</p>
  </div>
</div>

<h2>外送員（available=綠 / assigned=橙 / 其他=灰）</h2>
<table><tr><th>id</th><th>名字</th><th>座標</th><th>狀態</th><th>handling 訂單</th><th>更新位置（模擬 GPS 串流）</th></tr>$crows</table>

<h2>訂單（點編號進追蹤；先讓餐廳 accept→preparing，再派單）</h2>
<table><tr><th>order_id</th><th>餐廳</th><th>食客</th><th>狀態</th><th>外送員</th><th>ETA</th><th>餐廳→食客</th></tr>$orows</table>

<p class="hint">※ ETA = 備餐時間 + 兩段直線距離 ÷ 平均車速的教學近似（非真實路網）；地理索引為單機 geohash + haversine（非分散式）。
派單地理媒合 / CAS 原子指派防重複 / 訂單狀態機 / 隨外送員位置變化的即時 ETA 為真實邏輯。</p>
</div></body></html>
HTML;
}
