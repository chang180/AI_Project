<?php
declare(strict_types=1);

/**
 * Webhook 平台 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8008 -t public
 *
 * 路由：
 *   GET  /                          測試頁（註冊端點、發布事件、查投遞、查 DLQ）
 *   POST /api/endpoints             註冊端點 { url, events, mode }
 *   POST /api/events                發布事件 { type, data } → fan-out 入列
 *   POST /api/dispatch              觸發一輪投遞（模擬 worker 跑一輪）
 *   POST /api/deliveries/{id}/redeliver  重送 DLQ 中的投遞
 *   GET  /api/deliveries            投遞任務列表（JSON）
 *   GET  /api/dlq                   DLQ 列表（JSON）
 *   POST /receive                   內建測試接收端：驗 HMAC 後回 200（示範驗簽）
 */

require __DIR__ . '/../src/EndpointStore.php';
require __DIR__ . '/../src/Signer.php';
require __DIR__ . '/../src/DeliveryQueue.php';
require __DIR__ . '/../src/Dispatcher.php';

$dataDir   = __DIR__ . '/../data';
$endpoints = new EndpointStore($dataDir);
$signer    = new Signer();
$queue     = new DeliveryQueue($dataDir);
$dispatcher = new Dispatcher($endpoints, $queue, $signer);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/endpoints：註冊端點 ----
if ($method === 'POST' && $path === '/api/endpoints') {
    $in = read_input();
    $url = trim((string)($in['url'] ?? ''));
    $events = $in['events'] ?? ['payment.succeeded'];
    if (is_string($events)) {
        $events = array_values(array_filter(array_map('trim', explode(',', $events))));
    }
    $mode = (string)($in['mode'] ?? 'success');
    if ($url === '') {
        http_response_code(400);
        exit(json_out(['error' => 'url 必填']));
    }
    $rec = $endpoints->register($url, $events, $mode);
    if (wants_html()) { header('Location: /'); exit; }
    exit(json_out($rec));
}

// ---- POST /api/events：發布事件 → fan-out 入列 ----
if ($method === 'POST' && $path === '/api/events') {
    $in = read_input();
    $type = trim((string)($in['type'] ?? 'payment.succeeded'));
    $data = $in['data'] ?? ['amount' => 100, 'currency' => 'TWD'];
    if (is_string($data)) {
        $data = ['note' => $data];
    }
    // event_id = idempotency_key：貫穿 fan-out 出來的每一筆投遞，供接收方去重
    $eventId = 'evt_' . substr(bin2hex(random_bytes(6)), 0, 8);
    $payload = ['event_id' => $eventId, 'type' => $type, 'data' => $data];

    $subs = $endpoints->subscribersOf($type);
    foreach ($subs as $ep) {
        $queue->enqueue($eventId, $type, $payload, $ep['endpoint_id']); // 一事件 → N 筆投遞
    }
    if (wants_html()) { header('Location: /'); exit; }
    exit(json_out(['event_id' => $eventId, 'type' => $type, 'queued_deliveries' => count($subs)]));
}

// ---- POST /api/dispatch：觸發一輪投遞 ----
if ($method === 'POST' && $path === '/api/dispatch') {
    $log = $dispatcher->runOnce();
    if (wants_html()) { header('Location: /?dispatched=' . count($log)); exit; }
    exit(json_out(['processed' => count($log), 'log' => $log]));
}

// ---- POST /api/deliveries/{id}/redeliver：重送 DLQ ----
if ($method === 'POST' && preg_match('#^/api/deliveries/([A-Za-z0-9_]+)/redeliver$#', $path, $m)) {
    $task = $queue->get($m[1]);
    if (!$task) { http_response_code(404); exit(json_out(['error' => 'not found'])); }
    // 重置回 PENDING（保留 attempts 歷史可看，這裡歸零以便重新跑完整退避）
    $task['status'] = DeliveryQueue::PENDING;
    $task['attempts'] = 0;
    $task['next_retry_at'] = 0;
    $queue->save($task);
    if (wants_html()) { header('Location: /'); exit; }
    exit(json_out(['redelivered' => $m[1]]));
}

// ---- GET /api/deliveries ----
if ($method === 'GET' && $path === '/api/deliveries') {
    exit(json_out($queue->all()));
}

// ---- GET /api/dlq ----
if ($method === 'GET' && $path === '/api/dlq') {
    exit(json_out($queue->deadLetters()));
}

// ---- POST /receive：內建測試接收端，驗 HMAC 後回 200 ----
if ($method === 'POST' && $path === '/receive') {
    $body = (string) file_get_contents('php://input');
    $ts  = (string)($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '');
    $sig = (string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
    // demo：接收端用查詢字串傳入 secret（真實系統由設定取得）
    $secret = (string)($_GET['secret'] ?? '');
    if ($secret === '' || !$signer->verify($secret, $body, $ts, $sig)) {
        http_response_code(401);
        exit(json_out(['error' => '簽章驗證失敗']));
    }
    // 驗章通過 → 真實接收方此處應依 Idempotency-Key 去重後處理
    exit(json_out(['ok' => true, 'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($endpoints, $queue);
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
    $raw = (string) file_get_contents('php://input');
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
        parse_str($raw, $form);
        if ($form) {
            return $form;
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

function render_home(EndpointStore $endpoints, DeliveryQueue $queue): void
{
    $epRows = '';
    foreach ($endpoints->all() as $ep) {
        $badge = $ep['state'] === 'ACTIVE' ? '🟢' : '⛔';
        $epRows .= '<tr><td><code>' . htmlspecialchars($ep['endpoint_id']) . '</code></td>'
            . '<td>' . htmlspecialchars($ep['url']) . '</td>'
            . '<td>' . htmlspecialchars(implode(',', $ep['events'])) . '</td>'
            . '<td>' . htmlspecialchars($ep['mode']) . '</td>'
            . "<td>$badge " . htmlspecialchars($ep['state']) . '</td></tr>';
    }

    $statusColor = [
        'DELIVERED' => '#7ee787', 'DEAD' => '#ff7b72',
        'RETRY' => '#f0883e', 'PENDING' => '#58a6ff', 'DELIVERING' => '#58a6ff',
    ];
    $dlRows = '';
    foreach (array_reverse($queue->all()) as $t) {
        $c = $statusColor[$t['status']] ?? '#9aa7b3';
        $next = $t['next_retry_at'] > 0
            ? ('+' . max(0, $t['next_retry_at'] - time()) . 's')
            : '-';
        $actions = $t['status'] === DeliveryQueue::DEAD
            ? "<form method='post' action='/api/deliveries/{$t['delivery_id']}/redeliver' style='margin:0'><button>重送</button></form>"
            : '-';
        $dlRows .= '<tr><td><code>' . htmlspecialchars($t['delivery_id']) . '</code></td>'
            . '<td>' . htmlspecialchars($t['event_id']) . '</td>'
            . '<td>' . htmlspecialchars($t['endpoint_id']) . '</td>'
            . "<td style='color:$c;font-weight:700'>" . htmlspecialchars($t['status']) . '</td>'
            . '<td>' . (int)$t['attempts'] . '</td>'
            . '<td>' . htmlspecialchars((string)($t['last_code'] ?? '-')) . '</td>'
            . "<td>$next</td>"
            . "<td>$actions</td></tr>";
    }

    $dispatched = isset($_GET['dispatched']) ? (int)$_GET['dispatched'] : null;
    $banner = $dispatched !== null
        ? "<div class='box tip'><span class='label'>已跑一輪投遞，處理 $dispatched 筆任務</span>失敗端點會進入退避（看「下次重試」欄），反覆觸發直到達上限進 DLQ。</div>"
        : '';

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Webhook 平台 · Demo</title>
<style>
:root{--bg:#0f1419;--card:#1e2630;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--acc:#58a6ff;--acc2:#7ee787;--warn:#f0883e}
body{max-width:900px;margin:32px auto;padding:0 16px;background:var(--bg);color:var(--text);
  font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.6}
h1{font-size:1.6rem}h2{font-size:1.15rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin-top:1.6em}
code{background:#161b22;padding:2px 6px;border-radius:5px;color:var(--acc2);font-size:.85em}
.box{border-left:4px solid var(--acc2);background:#1a2027;padding:10px 14px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:.2em}
table{width:100%;border-collapse:collapse;margin:.8em 0;font-size:.85rem}
th,td{border:1px solid var(--border);padding:7px 9px;text-align:left;vertical-align:top}
th{background:#1a2027;color:var(--acc)}
form.inline{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:var(--card);
  border:1px solid var(--border);border-radius:10px;padding:14px;margin:.6em 0}
input,select,button{background:#161b22;color:var(--text);border:1px solid var(--border);
  border-radius:6px;padding:7px 10px;font-size:.9rem}
button{background:var(--acc);color:#0f1419;border:none;font-weight:700;cursor:pointer}
button:hover{opacity:.9}
.dim{color:var(--dim);font-size:.85rem}
</style></head><body>
<h1>🪝 Webhook 平台 · Demo</h1>
<p class="dim">聚焦考點：<b>HMAC 簽章 + 指數退避重試 + DLQ</b>。投遞結果以端點 <code>mode</code> 模擬（success/fail/slow），避免單執行緒自鎖；簽/驗章、狀態機、退避、DLQ 皆為真實邏輯。</p>
$banner

<h2>1. 註冊端點</h2>
<form class="inline" method="post" action="/api/endpoints">
  <input name="url" placeholder="https://acme.com/hook" value="https://acme.com/hook" required>
  <input name="events" placeholder="事件類型(逗號)" value="payment.succeeded">
  <select name="mode">
    <option value="success">mode: success（回 200）</option>
    <option value="fail">mode: fail（回 500，看重試/DLQ）</option>
    <option value="slow">mode: slow（回 504 逾時）</option>
  </select>
  <button type="submit">註冊端點</button>
</form>
<table><tr><th>endpoint_id</th><th>url</th><th>訂閱事件</th><th>mode</th><th>狀態</th></tr>$epRows</table>

<h2>2. 發布事件（fan-out 入列）</h2>
<form class="inline" method="post" action="/api/events">
  <input name="type" placeholder="事件類型" value="payment.succeeded" required>
  <input name="data" placeholder="data 備註" value="order #1234">
  <button type="submit">發布事件</button>
</form>

<h2>3. 觸發投遞（模擬 worker 跑一輪）</h2>
<form class="inline" method="post" action="/api/dispatch">
  <button type="submit">▶ 觸發一輪投遞</button>
  <span class="dim">失敗端點每按一次前進一次退避；達 6 次上限 → DEAD 進 DLQ 並停用端點。</span>
</form>

<h2>4. 投遞任務狀態</h2>
<table><tr><th>delivery_id</th><th>event_id(冪等鍵)</th><th>endpoint</th><th>狀態</th><th>嘗試</th><th>last_code</th><th>下次重試</th><th>操作</th></tr>$dlRows</table>
<p class="dim">PENDING→DELIVERING→DELIVERED / RETRY(退避中) / DEAD(DLQ)。DEAD 可手動重送。</p>
</body></html>
HTML;
}
