<?php
declare(strict_types=1);

/**
 * RoboTaxi 叫車 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8005 -t public
 *
 * 路由：
 *   GET  /                          測試頁（註冊司機、叫車、看媒合結果）
 *   POST /api/drivers               註冊司機 { id, lat, lng, plate }
 *   POST /api/drivers/{id}/location 更新司機位置（模擬 GPS 串流）{ lat, lng }
 *   GET  /api/drivers/nearby        查附近可用車 ?lat=&lng=&radius_km=&k=
 *   POST /api/rides                 叫車（媒合）{ rider_id, lat, lng }
 *   POST /api/rides/{id}/complete   完成行程，釋放車輛
 */

require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/GeoIndex.php';
require __DIR__ . '/../src/Matcher.php';

$store   = new Store(__DIR__ . '/../data');
$geo     = new GeoIndex($store);
$matcher = new Matcher($store, $geo);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/drivers：註冊司機 ----
if ($method === 'POST' && $path === '/api/drivers') {
    $in = body_input();
    $id    = trim((string)($in['id'] ?? ''));
    $lat   = isset($in['lat']) ? (float)$in['lat'] : null;
    $lng   = isset($in['lng']) ? (float)$in['lng'] : null;
    $plate = trim((string)($in['plate'] ?? '')) ?: strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    if ($id === '' || $lat === null || $lng === null) {
        http_response_code(400);
        exit(json_out(['error' => '需要 id / lat / lng']));
    }
    $d = $store->registerDriver($id, $lat, $lng, $plate);
    if (wants_html()) { header('Location: /?msg=' . urlencode("已註冊司機 $id")); exit; }
    exit(json_out($d));
}

// ---- POST /api/drivers/{id}/location：更新位置（GPS 串流示意）----
if ($method === 'POST' && preg_match('#^/api/drivers/([A-Za-z0-9_]+)/location$#', $path, $m)) {
    $in = body_input();
    $lat = isset($in['lat']) ? (float)$in['lat'] : null;
    $lng = isset($in['lng']) ? (float)$in['lng'] : null;
    if ($lat === null || $lng === null) {
        http_response_code(400);
        exit(json_out(['error' => '需要 lat / lng']));
    }
    $ok = $store->updateLocation($m[1], $lat, $lng);
    if (wants_html()) { header('Location: /?msg=' . urlencode("已更新 {$m[1]} 位置")); exit; }
    exit(json_out(['updated' => $ok, 'driver_id' => $m[1], 'lat' => $lat, 'lng' => $lng]));
}

// ---- GET /api/drivers/nearby：查附近可用車 ----
if ($method === 'GET' && $path === '/api/drivers/nearby') {
    $lat = (float)($_GET['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? 0);
    $radius = (float)($_GET['radius_km'] ?? 5);
    $k = (int)($_GET['k'] ?? 10);
    exit(json_out(['query' => compact('lat', 'lng', 'radius'), 'drivers' => $geo->nearby($lat, $lng, $radius, $k)]));
}

// ---- POST /api/rides：叫車（媒合）----
if ($method === 'POST' && $path === '/api/rides') {
    $in = body_input();
    $rider = trim((string)($in['rider_id'] ?? 'guest'));
    $lat = isset($in['lat']) ? (float)$in['lat'] : null;
    $lng = isset($in['lng']) ? (float)$in['lng'] : null;
    if ($lat === null || $lng === null) {
        http_response_code(400);
        exit(json_out(['error' => '需要 lat / lng']));
    }
    $ride = $matcher->requestRide($rider, $lat, $lng);
    if (!$ride) {
        if (wants_html()) { header('Location: /?msg=' . urlencode('附近無可用車')); exit; }
        http_response_code(409);
        exit(json_out(['error' => '附近無可用車（候選皆已被指派或超出範圍）']));
    }
    if (wants_html()) {
        header('Location: /?ride=' . urlencode($ride['ride_id']));
        exit;
    }
    http_response_code(201);
    exit(json_out($ride));
}

// ---- POST /api/rides/{id}/complete：完成行程 ----
if ($method === 'POST' && preg_match('#^/api/rides/([A-Za-z0-9_]+)/complete$#', $path, $m)) {
    $ok = $matcher->completeRide($m[1]);
    if (wants_html()) { header('Location: /?msg=' . urlencode($ok ? "行程 {$m[1]} 已完成、車輛釋放" : '行程不存在或已完成')); exit; }
    exit(json_out(['completed' => $ok, 'ride_id' => $m[1]]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($store, $matcher);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
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

function render_home(Store $store, Matcher $matcher): void
{
    $msg  = isset($_GET['msg']) ? htmlspecialchars((string)$_GET['msg']) : '';
    $rideId = $_GET['ride'] ?? null;

    $banner = '';
    if ($msg !== '') {
        $banner = "<div class='box tip'><span class='label'>訊息</span>$msg</div>";
    }
    if ($rideId) {
        $ride = $store->getRide((string)$rideId);
        if ($ride) {
            $banner .= "<div class='box tip'><span class='label'>媒合成功 {$ride['ride_id']}</span>"
                . "指派司機 <code>{$ride['driver_id']}</code>（{$ride['plate']}）·"
                . " 距離 {$ride['distance_km']} km · ETA {$ride['eta_min']} 分 · 加價 x{$ride['surge']}"
                . " <form method='post' action='/api/rides/{$ride['ride_id']}/complete' style='display:inline'>"
                . "<button type='submit'>完成行程（釋放車）</button></form></div>";
        }
    }

    // 司機表
    $drows = '';
    foreach ($store->allDrivers() as $id => $d) {
        $color = $d['status'] === 'available' ? '#7ee787' : ($d['status'] === 'matched' ? '#f0883e' : '#9aa7b3');
        $drows .= "<tr><td><code>" . htmlspecialchars((string)$id) . "</code></td>"
            . "<td>" . htmlspecialchars((string)$d['plate']) . "</td>"
            . "<td>{$d['lat']}, {$d['lng']}</td>"
            . "<td style='color:$color;font-weight:700'>{$d['status']}</td>"
            . "<td><form method='post' action='/api/drivers/" . htmlspecialchars((string)$id) . "/location' style='display:flex;gap:4px'>"
            . "<input name='lat' value='{$d['lat']}' style='width:90px'>"
            . "<input name='lng' value='{$d['lng']}' style='width:90px'>"
            . "<button type='submit'>更新位置</button></form></td></tr>";
    }
    $drows = $drows ?: "<tr><td colspan='5' style='color:#9aa7b3'>尚無司機，先在左側註冊幾台</td></tr>";

    // 行程表
    $rrows = '';
    foreach (array_reverse($store->allRides(), true) as $rid => $r) {
        $rrows .= "<tr><td><code>" . htmlspecialchars((string)$rid) . "</code></td>"
            . "<td>{$r['rider_id']}</td><td><code>{$r['driver_id']}</code></td>"
            . "<td>{$r['distance_km']} km</td><td>{$r['eta_min']} 分</td>"
            . "<td>x{$r['surge']}</td><td>{$r['status']}</td></tr>";
    }
    $rrows = $rrows ?: "<tr><td colspan='7' style='color:#9aa7b3'>尚無行程</td></tr>";

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RoboTaxi 叫車 · Demo</title>
<style>
  :root{--bg:#0f1419;--soft:#1a2027;--card:#1e2630;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--accent2:#7ee787;--warn:#f0883e}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.6}
  .wrap{max-width:1040px;margin:0 auto;padding:28px 20px 80px}
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
</style></head><body>
<div class="wrap">
<h1>🚖 RoboTaxi 叫車 · Demo</h1>
<p class="hint">聚焦 <strong>地理空間查附近</strong> + <strong>原子媒合（防並發重複指派）</strong>。座標可用台北市中心附近：25.04 / 121.56。</p>
$banner

<div class="grid">
  <div class="panel">
    <h2 style="margin-top:0">① 註冊司機</h2>
    <form class="block" method="post" action="/api/drivers">
      <input name="id" placeholder="司機 id 如 d1" required>
      <input name="lat" placeholder="lat 25.042" required style="width:110px">
      <input name="lng" placeholder="lng 121.565" required style="width:120px">
      <input name="plate" placeholder="車牌(可空)" style="width:110px">
      <button type="submit">註冊上線</button>
    </form>
    <p class="hint">註冊多台不同座標的車，模擬路上的可用車隊。</p>
  </div>
  <div class="panel">
    <h2 style="margin-top:0">② 乘客叫車（媒合）</h2>
    <form class="block" method="post" action="/api/rides">
      <input name="rider_id" placeholder="乘客 id 如 u1" value="u1">
      <input name="lat" placeholder="上車 lat 25.041" required style="width:110px">
      <input name="lng" placeholder="上車 lng 121.563" required style="width:120px">
      <button type="submit">叫車</button>
    </form>
    <p class="hint">系統找最近可用車並原子指派；連叫兩次驗證<strong>不會重複指派同一台</strong>。</p>
  </div>
</div>

<h2>司機狀態（available=綠 / matched=橙 / busy 等=灰）</h2>
<table><tr><th>id</th><th>車牌</th><th>座標</th><th>狀態</th><th>更新位置（模擬 GPS 串流）</th></tr>$drows</table>

<h2>行程紀錄</h2>
<table><tr><th>ride_id</th><th>乘客</th><th>司機</th><th>距離</th><th>ETA</th><th>加價</th><th>狀態</th></tr>$rrows</table>

<p class="hint">※ ETA 為直線距離 ÷ 平均車速近似（非真實路網）；地理索引為單機 geohash + haversine（非分散式）。查附近 / 最近 K 台 / 原子指派防重複 / 車輛狀態機為真實邏輯。</p>
</div></body></html>
HTML;
}
