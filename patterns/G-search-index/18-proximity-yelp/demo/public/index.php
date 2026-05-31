<?php
declare(strict_types=1);

/**
 * 附近的人 / 地點（Proximity Service / Yelp Nearby）Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8018 -t public
 *
 * 路由：
 *   GET  /                       測試頁（建立地點、半徑查詢、最近 K、桶檢視）
 *   POST /api/places             新增一筆地點 { name, category, rating, lat, lng }
 *   POST /api/seed               一鍵塞入一批示範地點（台北市區）
 *   GET  /api/nearby             半徑查詢 ?lat=&lng=&radius=&category=&min_rating=&offset=&limit=
 *   GET  /api/nearest            最近 K 個    ?lat=&lng=&k=&category=
 *   GET  /api/buckets            目前 geohash 桶摘要（哪些桶、各幾筆）
 */

require __DIR__ . '/../src/GeoHash.php';
require __DIR__ . '/../src/PlaceStore.php';
require __DIR__ . '/../src/SpatialIndex.php';

$geo   = new GeoHash();
$store = new PlaceStore(__DIR__ . '/../data');
$index = new SpatialIndex($geo, $store, 6);
$index->rebuild(); // 每次請求由地點資料重建索引（demo 簡化；真實系統索引常駐記憶體 / Redis）

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($path, '/') ?: '/';

// ---- POST /api/places：新增地點 ----
if ($method === 'POST' && $path === '/api/places') {
    $in       = read_input();
    $name     = trim((string)($in['name'] ?? ''));
    $category = trim((string)($in['category'] ?? ''));
    $rating   = (float)($in['rating'] ?? 0);
    $lat      = (float)($in['lat'] ?? 0);
    $lng      = (float)($in['lng'] ?? 0);
    if ($name === '' || $category === '') {
        http_response_code(400);
        exit(json_out(['error' => 'name 與 category 為必填']));
    }
    $rec = $store->add($name, $category, $rating, $lat, $lng);
    if (wants_html()) { header('Location: /?added=' . urlencode($name)); exit; }
    exit(json_out(['ok' => true, 'place' => $rec]));
}

// ---- POST /api/seed：塞入示範資料 ----
if ($method === 'POST' && $path === '/api/seed') {
    $n = seed_demo_places($store);
    if (wants_html()) { header('Location: /?seeded=' . $n); exit; }
    exit(json_out(['ok' => true, 'seeded' => $n, 'total' => $store->count()]));
}

// ---- GET /api/nearby：半徑查詢 ----
if ($method === 'GET' && $path === '/api/nearby') {
    $lat      = (float)($_GET['lat'] ?? 0);
    $lng      = (float)($_GET['lng'] ?? 0);
    $radius   = (float)($_GET['radius'] ?? 2.0);
    $category = isset($_GET['category']) && $_GET['category'] !== '' ? (string)$_GET['category'] : null;
    $minR     = (float)($_GET['min_rating'] ?? 0);
    $offset   = max(0, (int)($_GET['offset'] ?? 0));
    $limit    = max(1, (int)($_GET['limit'] ?? 20));
    $res      = $index->nearby($lat, $lng, $radius, $category, $minR, $offset, $limit);
    exit(json_out([
        'query'           => compact('lat', 'lng', 'radius', 'category', 'minR', 'offset', 'limit'),
        'center_geohash'  => $geo->encode($lat, $lng, 6),
        'buckets_scanned' => $res['buckets_scanned'],
        'candidates'      => $res['candidates'],
        'total_matched'   => $res['total_matched'],
        'results'         => $res['results'],
        'cache'           => $store->cacheStats(),
    ]));
}

// ---- GET /api/nearest：最近 K 個 ----
if ($method === 'GET' && $path === '/api/nearest') {
    $lat      = (float)($_GET['lat'] ?? 0);
    $lng      = (float)($_GET['lng'] ?? 0);
    $k        = max(1, (int)($_GET['k'] ?? 5));
    $category = isset($_GET['category']) && $_GET['category'] !== '' ? (string)$_GET['category'] : null;
    $res      = $index->nearestK($lat, $lng, $k, $category);
    exit(json_out([
        'query'           => compact('lat', 'lng', 'k', 'category'),
        'precision_used'  => $res['precision_used'],
        'buckets_scanned' => $res['buckets_scanned'],
        'results'         => $res['results'],
    ]));
}

// ---- GET /api/buckets：桶摘要 ----
if ($method === 'GET' && $path === '/api/buckets') {
    exit(json_out(['precision' => 6, 'buckets' => $index->bucketSummary()]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($store, $index, $geo);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
function read_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
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

/** 塞一批台北市區地點（涵蓋不同 geohash 桶 + 跨格邊界，便於展示鄰格效果） */
function seed_demo_places(PlaceStore $store): int
{
    $places = [
        ['台北101觀景台',   '景點', 4.6, 25.0339, 121.5645],
        ['鼎泰豐信義店',     '餐廳', 4.7, 25.0330, 121.5650],
        ['誠品信義店',       '書店', 4.5, 25.0395, 121.5667],
        ['象山步道入口',     '景點', 4.8, 25.0270, 121.5705],
        ['市府轉運站',       '交通', 4.0, 25.0410, 121.5660],
        ['國父紀念館',       '景點', 4.6, 25.0400, 121.5600],
        ['微風南山',         '購物', 4.4, 25.0350, 121.5670],
        ['通化夜市',         '餐廳', 4.3, 25.0300, 121.5530],
        ['大安森林公園',     '景點', 4.7, 25.0295, 121.5350],
        ['台北車站',         '交通', 4.1, 25.0478, 121.5170],
        ['西門紅樓',         '景點', 4.4, 25.0420, 121.5070],
        ['龍山寺',           '景點', 4.6, 25.0370, 121.4998],
    ];
    $n = 0;
    foreach ($places as $p) {
        $store->add($p[0], $p[1], $p[2], $p[3], $p[4]);
        $n++;
    }
    return $n;
}

function render_home(PlaceStore $store, SpatialIndex $index, GeoHash $geo): void
{
    $added   = $_GET['added']   ?? null;
    $seeded  = $_GET['seeded']  ?? null;
    $total   = $store->count();

    // 地點列表
    $rows = '';
    foreach ($store->all() as $r) {
        $gh = $geo->encode((float)$r['lat'], (float)$r['lng'], 6);
        $rows .= '<tr><td><code>' . htmlspecialchars($r['id']) . '</code></td>'
            . '<td>' . htmlspecialchars($r['name']) . '</td>'
            . '<td>' . htmlspecialchars($r['category']) . '</td>'
            . '<td>' . htmlspecialchars((string)$r['rating']) . '</td>'
            . '<td>' . htmlspecialchars((string)$r['lat']) . ', ' . htmlspecialchars((string)$r['lng']) . '</td>'
            . '<td><code>' . htmlspecialchars($gh) . '</code></td></tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="6" style="color:#888">尚無地點，先按「塞入示範地點」。</td></tr>';
    }

    // 桶摘要
    $bucketRows = '';
    foreach ($index->bucketSummary() as $hash => $cnt) {
        $bucketRows .= '<tr><td><code>' . htmlspecialchars($hash) . '</code></td><td>' . $cnt . '</td></tr>';
    }
    if ($bucketRows === '') {
        $bucketRows = '<tr><td colspan="2" style="color:#888">尚無桶。</td></tr>';
    }

    $banner = '';
    if ($seeded !== null) {
        $banner = "<div class='box tip'><span class='label'>已塞入 " . (int)$seeded . " 筆示範地點</span>目前共 $total 筆，下方可直接做半徑/最近K查詢。</div>";
    } elseif ($added !== null) {
        $banner = "<div class='box tip'><span class='label'>已新增地點</span>" . htmlspecialchars((string)$added) . "</div>";
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>附近地點 · Proximity Demo</title>
<style>
:root{color-scheme:dark}
body{max-width:920px;margin:32px auto;padding:0 16px;background:#0f1419;color:#e6edf3;
 font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.6}
h1{font-size:1.6rem}h2{font-size:1.15rem;border-bottom:1px solid #2a3540;padding-bottom:.3em;margin-top:1.6em}
a{color:#58a6ff}code{background:#161b22;padding:2px 6px;border-radius:5px;color:#7ee787;font-size:.88em}
.box{border-left:4px solid #7ee787;background:#1a2027;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:.2em}
table{width:100%;border-collapse:collapse;margin:.8em 0;font-size:.9rem}
td,th{border:1px solid #2a3540;padding:7px 10px;text-align:left;vertical-align:top}
th{background:#1a2027;color:#58a6ff}
input,select,button{background:#161b22;color:#e6edf3;border:1px solid #2a3540;border-radius:6px;padding:6px 10px;font-size:.9rem}
button{background:#1f6feb;border-color:#1f6feb;cursor:pointer}button:hover{background:#388bfd}
form{margin:.6em 0}fieldset{border:1px solid #2a3540;border-radius:8px;margin:1em 0}
pre{background:#161b22;border:1px solid #2a3540;border-radius:8px;padding:12px;overflow:auto;font-size:.82rem}
.muted{color:#9aa7b3;font-size:.85rem}
.row>*{margin-right:6px;margin-bottom:6px}
</style></head><body>
<h1>📍 附近地點 · Proximity / Yelp Nearby Demo</h1>
<p class="muted">geohash 前綴分桶 → 中心格 + 8 鄰格粗篩 → haversine 精算排序。聚焦空間索引，非 CRUD。</p>
$banner

<fieldset><legend>① 建立資料</legend>
  <form method="post" action="/api/seed"><button type="submit">塞入示範地點（台北市區 12 筆）</button>
    <span class="muted">目前共 $total 筆</span></form>
  <form method="post" action="/api/places" class="row">
    <input name="name" placeholder="名稱" required>
    <input name="category" placeholder="類別（餐廳/景點…）" required>
    <input name="rating" placeholder="評分" size="4" value="4.5">
    <input name="lat" placeholder="lat" size="9" value="25.0339">
    <input name="lng" placeholder="lng" size="9" value="121.5645">
    <button type="submit">新增地點</button>
  </form>
</fieldset>

<fieldset><legend>② 半徑查詢（依距離排序，可選類別 / 最低評分 / 分頁）</legend>
  <form method="get" action="/api/nearby" class="row" target="_blank">
    <input name="lat" value="25.0339" size="9">
    <input name="lng" value="121.5645" size="9">
    <input name="radius" value="2" size="4" title="半徑(km)">
    <select name="category"><option value="">全部類別</option>
      <option>餐廳</option><option>景點</option><option>書店</option>
      <option>購物</option><option>交通</option></select>
    <input name="min_rating" value="0" size="4" title="最低評分">
    <input name="limit" value="20" size="4" title="每頁筆數">
    <button type="submit">查附近（JSON）</button>
  </form>
  <p class="muted">回傳含 <code>buckets_scanned</code>（實際掃描了哪 9 個 geohash 桶，含鄰格）與每筆 <code>distance_km</code>。</p>
</fieldset>

<fieldset><legend>③ 最近 K 個</legend>
  <form method="get" action="/api/nearest" class="row" target="_blank">
    <input name="lat" value="25.0339" size="9">
    <input name="lng" value="121.5645" size="9">
    <input name="k" value="5" size="4" title="K">
    <select name="category"><option value="">全部類別</option>
      <option>餐廳</option><option>景點</option><option>書店</option>
      <option>購物</option><option>交通</option></select>
    <button type="submit">查最近 K（JSON）</button>
  </form>
</fieldset>

<h2>目前地點（含各自 geohash 桶鍵，精度 6）</h2>
<table><tr><th>id</th><th>名稱</th><th>類別</th><th>評分</th><th>座標</th><th>geohash</th></tr>$rows</table>

<h2>geohash 桶摘要（<a href="/api/buckets" target="_blank">/api/buckets</a>）</h2>
<table><tr><th>桶鍵（geohash 前綴）</th><th>地點數</th></tr>$bucketRows</table>

<p class="muted">※ 本機 PHP 未載入 mbstring，程式全程使用 strlen/substr。空間索引、鄰格、半徑/最近K、屬性過濾、分頁皆為真實可執行邏輯；地點為示範靜態資料。</p>
</body></html>
HTML;
}
