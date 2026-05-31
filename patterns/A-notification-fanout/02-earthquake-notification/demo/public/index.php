<?php
declare(strict_types=1);

/**
 * 地震預警系統 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8002 -t public
 *
 * 聚焦考點：地理篩選（haversine 半徑查詢）+ fan-out 投遞（冪等/退避/DLQ）。
 *
 * 路由：
 *   GET  /                       測試頁（註冊裝置、觸發地震、看 fan-out 結果）
 *   POST /api/devices            註冊裝置 { lat, lng, label }
 *   POST /api/devices/seed       一鍵載入示範裝置（散佈於台灣各地）
 *   POST /api/quake              觸發地震 { lat, lng, magnitude, radius_km }
 *                                → 篩選收件人 → fan-out → 投遞，回傳結果
 */

require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/GeoIndex.php';
require __DIR__ . '/../src/NotificationQueue.php';
require __DIR__ . '/../src/Dispatcher.php';

$store = new Store(__DIR__ . '/../data');
$geo   = new GeoIndex();

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/devices：註冊一台裝置 ----
if ($method === 'POST' && $path === '/api/devices') {
    $in = read_input();
    $lat = (float) ($in['lat'] ?? 0);
    $lng = (float) ($in['lng'] ?? 0);
    $label = trim((string) ($in['label'] ?? ''));
    if ($lat === 0.0 || $lng === 0.0) {
        http_response_code(400);
        exit(json_out(['error' => 'lat/lng 必填']));
    }
    $id = register_device($store, $geo, $lat, $lng, $label);
    redirect_or_json('/?msg=' . rawurlencode("已註冊裝置 $id"), ['device_id' => $id]);
}

// ---- POST /api/devices/seed：載入示範裝置 ----
if ($method === 'POST' && $path === '/api/devices/seed') {
    $samples = [
        ['台北車站', 25.0478, 121.5170],
        ['新北板橋', 25.0143, 121.4670],
        ['桃園市區', 24.9936, 121.3010],
        ['台中市區', 24.1477, 120.6736],
        ['南投埔里', 23.9650, 120.9700],
        ['花蓮市區', 23.9871, 121.6015],
        ['台南市區', 22.9999, 120.2270],
        ['高雄市區', 22.6273, 120.3014],
    ];
    foreach ($samples as [$label, $lat, $lng]) {
        register_device($store, $geo, $lat, $lng, $label);
    }
    redirect_or_json('/?msg=' . rawurlencode('已載入 8 台示範裝置'), ['seeded' => count($samples)]);
}

// ---- POST /api/quake：觸發地震 → 篩選 → fan-out → 投遞 ----
if ($method === 'POST' && $path === '/api/quake') {
    $in = read_input();
    $lat = (float) ($in['lat'] ?? 0);
    $lng = (float) ($in['lng'] ?? 0);
    $mag = (float) ($in['magnitude'] ?? 6.0);
    $radius = (float) ($in['radius_km'] ?? 100);

    $eventId = 'eq_' . gmdate('YmdHis') . '_' . random_int(100, 999);
    $event = [
        'event_id'           => $eventId,
        'epicenter'          => ['lat' => $lat, 'lng' => $lng],
        'magnitude'          => $mag,
        'affected_radius_km' => $radius,
        'status'             => 'new',
        'detected_at'        => gmdate('c'),
    ];
    $store->putEvent($event);

    // 1) 地理篩選：從全體裝置框出震央半徑內的收件人
    $devices    = $store->allDevices();
    $recipients = $geo->withinRadius($devices, $lat, $lng, $radius);

    // 2) fan-out on write：展開成逐裝置任務入列
    $queue = new NotificationQueue();
    $fanOut = $queue->fanOut($event, $recipients);

    // 3) 投遞：冪等去重 + 指數退避重試 + DLQ
    $dispatcher = new Dispatcher($store);
    $summary = $dispatcher->dispatch($queue->drain());

    redirect_or_json(
        '/?event=' . rawurlencode($eventId),
        [
            'event'        => $event,
            'total_devices'=> count($devices),
            'fan_out'      => $fanOut,
            'summary'      => $summary,
        ]
    );
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($store, $geo);
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

/** 註冊裝置：算 geohash（粗篩鍵）並寫入儲存 */
function register_device(Store $store, GeoIndex $geo, float $lat, float $lng, string $label): string
{
    $id = 'dev_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $store->putDevice([
        'device_id'    => $id,
        'device_token' => 'tok_' . bin2hex(random_bytes(6)),
        'label'        => $label !== '' ? $label : $id,
        'lat'          => $lat,
        'lng'          => $lng,
        'geohash'      => $geo->geohash($lat, $lng, 6),  // 預算粗篩鍵
        'channels'     => ['push'],
        'updated_at'   => gmdate('c'),
    ]);
    return $id;
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** 表單送出 → 導回首頁；API（JSON/Accept 非 html）→ 回 JSON */
function redirect_or_json(string $location, array $jsonPayload): void
{
    $wantsHtml = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
    if ($wantsHtml) {
        header('Location: ' . $location);
        exit;
    }
    exit(json_out($jsonPayload));
}

function render_home(Store $store, GeoIndex $geo): void
{
    $msg = isset($_GET['msg']) ? '<div class="box tip"><span class="label">' . htmlspecialchars((string) $_GET['msg']) . '</span></div>' : '';

    // 裝置列表
    $devRows = '';
    foreach ($store->allDevices() as $d) {
        $devRows .= '<tr><td>' . htmlspecialchars($d['label']) . '</td>'
            . '<td><code>' . htmlspecialchars($d['device_id']) . '</code></td>'
            . '<td>' . number_format((float) $d['lat'], 4) . ', ' . number_format((float) $d['lng'], 4) . '</td>'
            . '<td><code>' . htmlspecialchars($d['geohash']) . '</code></td></tr>';
    }
    if ($devRows === '') {
        $devRows = '<tr><td colspan="4" style="color:#888">尚無裝置，先按「載入示範裝置」</td></tr>';
    }

    // 若剛觸發地震，重新計算結果以呈現 fan-out 詳情
    $resultBlock = '';
    if (isset($_GET['event'])) {
        $event = $store->getEvent((string) $_GET['event']);
        if ($event) {
            $ep = $event['epicenter'];
            $devices = $store->allDevices();
            $recipients = $geo->withinRadius($devices, (float) $ep['lat'], (float) $ep['lng'], (float) $event['affected_radius_km']);
            $deliveries = $store->deliveriesForEvent($event['event_id']);
            // 以 device_id 索引投遞紀錄
            $byDevice = [];
            foreach ($deliveries as $dl) {
                $byDevice[$dl['device_id']] = $dl;
            }

            $rows = '';
            $sent = $failed = 0;
            foreach ($recipients as $r) {
                $dl = $byDevice[$r['device_id']] ?? ['status' => '?', 'attempts' => 0, 'latency_ms' => 0];
                $status = $dl['status'];
                if ($status === 'sent') { $sent++; $badge = '<span style="color:#7ee787">✓ sent</span>'; }
                elseif ($status === 'failed') { $failed++; $badge = '<span style="color:#ff7b72">✗ failed→DLQ</span>'; }
                else { $badge = htmlspecialchars((string) $status); }
                $eta = (int) round(((float) $r['distance_km']) / 3.5);
                $rows .= '<tr><td>' . htmlspecialchars($r['label']) . '</td>'
                    . '<td>' . $r['distance_km'] . ' km</td>'
                    . '<td>~' . $eta . ' 秒</td>'
                    . '<td>' . $badge . '</td>'
                    . '<td>' . (int) ($dl['attempts'] ?? 0) . '</td>'
                    . '<td>' . (int) ($dl['latency_ms'] ?? 0) . ' ms</td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="6" style="color:#888">此半徑內無任何裝置（範圍外的人不會被打擾）</td></tr>';
            }

            $resultBlock = '<div class="box warn"><span class="label">地震事件 ' . htmlspecialchars($event['event_id']) . '</span>'
                . '震央 (' . $ep['lat'] . ', ' . $ep['lng'] . ')｜規模 M' . $event['magnitude']
                . '｜影響半徑 ' . $event['affected_radius_km'] . ' km<br>'
                . 'fan-out 受影響裝置：<strong>' . count($recipients) . '</strong> / 全體 ' . count($devices) . ' 台'
                . '｜投遞成功 <strong>' . $sent . '</strong>｜進 DLQ <strong>' . $failed . '</strong></div>'
                . '<table><tr><th>裝置</th><th>距震央</th><th>S波抵達(估)</th><th>投遞狀態</th><th>嘗試次數</th><th>模擬延遲</th></tr>' . $rows . '</table>';
        }
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>地震預警系統 · Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>body{max-width:860px;margin:40px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box.warn{border-left-color:#f0883e}
.box .label{font-weight:700;display:block;margin-bottom:.2em}code{background:#222;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse;font-size:.9rem}td,th{border:1px solid #444;padding:7px;text-align:left}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.row input{width:120px}
fieldset{border:1px solid #444;border-radius:8px;margin:1em 0}</style>
</head><body>
<h1>🌐 地震預警系統 · Demo</h1>
<p>聚焦考點：<strong>地理篩選（haversine 半徑查詢）+ fan-out 投遞</strong>（冪等去重 / 指數退避重試 / DLQ）。不是 CRUD 表單。</p>
$msg
$resultBlock

<fieldset><legend>① 註冊裝置</legend>
<form method="post" action="/api/devices" class="row">
  <label>標籤 <input name="label" placeholder="台北車站"></label>
  <label>lat <input name="lat" type="number" step="0.0001" placeholder="25.0478"></label>
  <label>lng <input name="lng" type="number" step="0.0001" placeholder="121.5170"></label>
  <button type="submit">註冊</button>
</form>
<form method="post" action="/api/devices/seed"><button type="submit">⚡ 載入 8 台示範裝置（台灣各地）</button></form>
</fieldset>

<fieldset><legend>② 觸發地震（震央 + 規模 + 影響半徑）</legend>
<form method="post" action="/api/quake" class="row">
  <label>震央 lat <input name="lat" type="number" step="0.0001" value="23.9650"></label>
  <label>震央 lng <input name="lng" type="number" step="0.0001" value="120.9700"></label>
  <label>規模 M <input name="magnitude" type="number" step="0.1" value="6.5"></label>
  <label>半徑 km <input name="radius_km" type="number" step="1" value="120"></label>
  <button type="submit">🚨 觸發地震</button>
</form>
<p style="color:#888;font-size:.85rem">預設震央在南投埔里（台灣地理中心），半徑 120km → 觀察哪些城市落在範圍內、各自距離與 S 波抵達秒數。</p>
</fieldset>

<h2>已註冊裝置</h2>
<table><tr><th>標籤</th><th>device_id</th><th>座標</th><th>geohash(粗篩鍵)</th></tr>$devRows</table>
<p style="color:#888;font-size:.85rem">※ 推播為模擬投遞（隨機延遲與失敗，~15% 觸發重試）；地理篩選 / fan-out / 冪等去重 / 退避重試 / DLQ 為真實邏輯。再次對同一事件觸發會被冪等去重。</p>
</body></html>
HTML;
}
