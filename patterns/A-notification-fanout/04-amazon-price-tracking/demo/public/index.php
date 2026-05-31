<?php
declare(strict_types=1);

/**
 * Amazon 價格追蹤 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8004 -t public
 *
 * 聚焦考點：條件匹配（反向索引）+ 智能去抖狀態機（ARMED/TRIGGERED/COOLDOWN）+ 冪等。
 *
 * 路由：
 *   GET  /                    測試頁（建立追蹤、餵價格、看狀態與觸發）
 *   POST /api/watches         建立追蹤 { user_id, sku, type, threshold }
 *   POST /api/prices/push     推送一筆新價格 { sku, price } → 匹配 + 去抖
 *   POST /api/prices/tick     對所有追蹤的 sku 隨機波動一輪（模擬一次輪詢）
 *   GET  /api/users/{id}/watches   某使用者的追蹤清單與狀態
 */

require __DIR__ . '/../src/Watchlist.php';
require __DIR__ . '/../src/PriceFeed.php';
require __DIR__ . '/../src/AlertEngine.php';

$dataDir = __DIR__ . '/../data';
$watchlist = new Watchlist($dataDir);
$feed = new PriceFeed($dataDir);
$engine = new AlertEngine($watchlist, $dataDir);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/watches：建立追蹤 ----
if ($method === 'POST' && $path === '/api/watches') {
    $in = read_input();
    $userId = (int) ($in['user_id'] ?? 0);
    $sku = trim((string) ($in['sku'] ?? ''));
    $type = ($in['type'] ?? 'target') === 'drop_pct' ? 'drop_pct' : 'target';
    $threshold = (float) ($in['threshold'] ?? 0);
    if ($userId <= 0 || $sku === '' || $threshold <= 0) {
        http_response_code(400);
        exit(json_out(['error' => 'user_id / sku / threshold 必填且需為正']));
    }
    // 取現價作為 drop_pct 的基準；若該 sku 尚無價格，以 threshold 附近給個初始價
    $base = $feed->priceOf($sku);
    if ($base === null) {
        $base = $type === 'target' ? round($threshold * 1.2, 2) : 1000.0;
        $feed->ensure($sku, $base);
    }
    $watchId = $watchlist->add($userId, $sku, $type, $threshold, $base);
    redirect_or_json('/?msg=' . urlencode("已建立追蹤 $watchId"), [
        'watch_id' => $watchId,
        'state'    => 'ARMED',
        'sku'      => $sku,
        'base_price' => $base,
    ]);
}

// ---- POST /api/prices/push：推送一筆新價格 ----
if ($method === 'POST' && $path === '/api/prices/push') {
    $in = read_input();
    $sku = trim((string) ($in['sku'] ?? ''));
    $price = (float) ($in['price'] ?? -1);
    if ($sku === '' || $price < 0) {
        http_response_code(400);
        exit(json_out(['error' => 'sku / price 必填']));
    }
    $event = $feed->push($sku, $price);
    if ($event === null) {
        redirect_or_json('/?msg=' . urlencode("價格沒變，事件被變更偵測丟棄"), ['changed' => false]);
    }
    $fired = $engine->onPriceEvent($event);
    redirect_or_json(
        '/?msg=' . urlencode(sprintf('推送 %s=%.2f → 觸發 %d 筆提醒', $sku, $price, count($fired))),
        ['event' => $event, 'fired' => $fired]
    );
}

// ---- POST /api/prices/tick：隨機波動一輪 ----
if ($method === 'POST' && $path === '/api/prices/tick') {
    $skus = $watchlist->skus();
    $events = $feed->randomTick($skus);
    $allFired = [];
    foreach ($events as $event) {
        foreach ($engine->onPriceEvent($event) as $a) {
            $allFired[] = $a;
        }
    }
    redirect_or_json(
        '/?msg=' . urlencode(sprintf('波動一輪：%d 筆變價 → 觸發 %d 筆提醒（去抖後）', count($events), count($allFired))),
        ['events' => $events, 'fired' => $allFired]
    );
}

// ---- GET /api/users/{id}/watches ----
if ($method === 'GET' && preg_match('#^/api/users/(\d+)/watches$#', $path, $m)) {
    $uid = (int) $m[1];
    $rows = array_values(array_filter(
        $watchlist->all(),
        static fn(array $w): bool => (int) $w['user_id'] === $uid
    ));
    exit(json_out(['user_id' => $uid, 'watches' => $rows]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($watchlist, $feed);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
/** @return array<string,mixed> */
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
    }
    return [];
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function wants_html(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
}

function redirect_or_json(string $location, array $json): never
{
    if (wants_html()) {
        header('Location: ' . $location);
        exit;
    }
    exit(json_out($json));
}

function badge(string $state): string
{
    // 用既有 .tag 樣式呈現狀態：ARMED=group(藍) COOLDOWN=time(橘) TRIGGERED 預設
    $cls = match ($state) {
        'ARMED'    => 'tag group',
        'COOLDOWN' => 'tag time',
        default    => 'tag',
    };
    return "<span class=\"$cls\">$state</span>";
}

function render_home(Watchlist $watchlist, PriceFeed $feed): void
{
    $msg = isset($_GET['msg']) ? htmlspecialchars((string) $_GET['msg']) : '';
    $banner = $msg !== ''
        ? "<div class=\"box tip\"><span class=\"label\">結果</span>$msg</div>"
        : '';

    // 追蹤清單表格
    $rows = '';
    foreach ($watchlist->all() as $w) {
        $cond = $w['type'] === 'target'
            ? '≤ ' . number_format((float) $w['threshold'], 2)
            : '跌 ' . $w['threshold'] . '% (觸發價 ' . number_format((float) $w['trigger_price'], 2) . ')';
        $last = $w['last_price'] !== null ? number_format((float) $w['last_price'], 2) : '—';
        $rows .= '<tr>'
            . '<td><code>' . htmlspecialchars((string) $w['watch_id']) . '</code></td>'
            . '<td>' . htmlspecialchars((string) $w['user_id']) . '</td>'
            . '<td><code>' . htmlspecialchars((string) $w['sku']) . '</code></td>'
            . '<td>' . htmlspecialchars($cond) . '</td>'
            . '<td>' . $last . '</td>'
            . '<td>' . badge((string) $w['state']) . '</td>'
            . '<td>' . (int) $w['alerts_sent'] . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="7" style="color:var(--text-dim)">尚無追蹤，先在上方建立一筆。</td></tr>';
    }

    // 現價表
    $priceRows = '';
    foreach ($feed->all() as $sku => $p) {
        $priceRows .= '<tr><td><code>' . htmlspecialchars((string) $sku) . '</code></td>'
            . '<td>' . number_format((float) $p, 2) . '</td></tr>';
    }
    if ($priceRows === '') {
        $priceRows = '<tr><td colspan="2" style="color:var(--text-dim)">尚無價格。</td></tr>';
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Amazon 價格追蹤 · Demo</title>
<link rel="stylesheet" href="../../../../assets/style.css">
</head><body>
<div class="topbar">
  <span class="brand">🏛️ 系統設計</span>
  <a href="../../notes.html">← 回筆記</a>
  <span style="color:var(--text-dim)">④ Amazon 價格追蹤 · Demo</span>
</div>
<div class="wrap">
  <h1>💰 Amazon 價格追蹤 · Demo</h1>
  <p class="subtitle">聚焦：條件匹配（反向索引）+ 智能去抖狀態機 + 冪等去重。</p>
  $banner

  <div class="box"><span class="label">操作流程</span>
    1) 建立追蹤（商品 + 目標價）→ 2) 餵入幾筆價格變動 → 3) 看哪些觸發提醒。
    試試「連續推送低於目標價」：第一次觸發、之後進 <code>COOLDOWN</code> 不再發（去抖）；
    把價格<strong>拉回觸發價 ×1.05 以上</strong>且冷卻結束才會 re-arm 回 <code>ARMED</code>。
  </div>

  <h2>1. 建立追蹤</h2>
  <form method="post" action="/api/watches">
    <p>使用者 ID <input name="user_id" value="42" size="6" required>
    商品 SKU <input name="sku" value="B0iphone" required>
    類型 <select name="type"><option value="target">目標價</option><option value="drop_pct">跌幅%</option></select>
    門檻 <input name="threshold" value="899" size="8" required>
    <button type="submit">建立追蹤</button></p>
  </form>

  <h2>2. 餵入價格</h2>
  <form method="post" action="/api/prices/push" style="display:inline">
    <input name="sku" value="B0iphone" size="12" required>
    <input name="price" value="880" size="8" required>
    <button type="submit">推送一筆新價格</button>
  </form>
  <form method="post" action="/api/prices/tick" style="display:inline; margin-left:12px">
    <button type="submit">隨機波動一輪（模擬輪詢）</button>
  </form>

  <h2>3. 追蹤狀態</h2>
  <table>
    <tr><th>watch_id</th><th>使用者</th><th>SKU</th><th>條件</th><th>最近價</th><th>狀態</th><th>已發提醒</th></tr>
    $rows
  </table>

  <h2>目前各商品現價（PriceFeed）</h2>
  <table><tr><th>SKU</th><th>現價</th></tr>$priceRows</table>

  <div class="box warn"><span class="label">誠實聲明</span>
    反向索引匹配、去抖狀態機、冪等去重為<strong>真實可執行邏輯</strong>；但用 JSON 檔模擬儲存，
    未接真正的 Redis ZSET / Kafka / 寄信服務。上生產時替換基建即可，狀態機邏輯不變。
  </div>
  <footer>④ Amazon 價格追蹤 · A 組（通知/Fan-out）· 系統設計學習專案</footer>
</div>
</body></html>
HTML;
}
