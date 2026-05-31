<?php
declare(strict_types=1);

/**
 * News Feed 動態牆 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8015 -t public
 *
 * 聚焦「推 / 拉 / hybrid fan-out 取捨」，不是 CRUD。
 *
 * 路由：
 *   GET  /                       測試頁（操作 + 視覺化推/拉）
 *   POST /api/follow             建立追蹤 { follower, followee }
 *   POST /api/post               發文 { author, text } → 回 fanout 策略
 *   GET  /api/feed?user=U        讀某人 feed（hybrid，含 source 標記）
 *   GET  /api/plan               每位使用者被判定推 or 拉
 *   POST /api/seed               一鍵建立示範資料（含一個名人）
 */

require __DIR__ . '/../src/SocialGraph.php';
require __DIR__ . '/../src/FeedStore.php';
require __DIR__ . '/../src/FeedService.php';

$dataDir = __DIR__ . '/../data';
$graph = new SocialGraph($dataDir);
$store = new FeedStore($dataDir);
$svc = new FeedService($graph, $store);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

/** 從表單或 JSON body 取參數 */
function input(string $key): string
{
    $v = $_POST[$key] ?? null;
    if ($v === null) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $j = json_decode($raw, true);
            $v = is_array($j) ? ($j[$key] ?? null) : null;
        }
    }
    return trim((string) ($v ?? ''));
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

// ---- POST /api/follow ----
if ($method === 'POST' && $path === '/api/follow') {
    $follower = input('follower');
    $followee = input('followee');
    if ($follower === '' || $followee === '') {
        http_response_code(400);
        exit(json_out(['error' => 'follower / followee 必填']));
    }
    $graph->follow($follower, $followee);
    if (wants_html()) { header('Location: /'); exit; }
    exit(json_out(['ok' => true, 'follower' => $follower, 'followee' => $followee]));
}

// ---- POST /api/post：發文，回傳 fan-out 策略 ----
if ($method === 'POST' && $path === '/api/post') {
    $author = input('author');
    $text = input('text');
    if ($author === '' || $text === '') {
        http_response_code(400);
        exit(json_out(['error' => 'author / text 必填']));
    }
    $result = $svc->publish($author, $text);
    if (wants_html()) { header('Location: /?feed=' . rawurlencode($author)); exit; }
    exit(json_out($result));
}

// ---- GET /api/feed?user=U：讀 feed（hybrid） ----
if ($method === 'GET' && $path === '/api/feed') {
    $user = trim((string) ($_GET['user'] ?? ''));
    if ($user === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user 必填']));
    }
    exit(json_out($svc->buildFeed($user)));
}

// ---- GET /api/plan：每位使用者推 or 拉 ----
if ($method === 'GET' && $path === '/api/plan') {
    exit(json_out(['threshold' => SocialGraph::CELEBRITY_THRESHOLD, 'plan' => $svc->fanoutPlan()]));
}

// ---- POST /api/seed：一鍵示範資料（含名人 star） ----
if ($method === 'POST' && $path === '/api/seed') {
    // star 將被多人追蹤 → 粉絲數超過門檻成為名人（拉）
    foreach (['alice', 'bob', 'carol', 'dave'] as $fan) {
        $graph->follow($fan, 'star');     // 大家都追蹤 star（名人）
    }
    $graph->follow('alice', 'bob');       // alice 也追蹤一般使用者 bob（推）
    $graph->follow('alice', 'carol');     // alice 追蹤 carol（推）
    $svc->publish('bob', 'bob：今天午餐吃拉麵');           // bob 一般 → 推給 alice
    $svc->publish('star', 'star：百萬粉絲名人發文（不推，讀時拉）');
    $svc->publish('carol', 'carol：分享一張貓咪照片');     // carol 一般 → 推給 alice
    $svc->publish('star', 'star：名人第二篇（一樣讀時拉）');
    if (wants_html()) { header('Location: /?feed=alice'); exit; }
    exit(json_out(['ok' => true, 'note' => 'seed 完成，看 alice 的 feed']));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($graph, $store, $svc);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 測試頁 ============
function render_home(SocialGraph $graph, FeedStore $store, FeedService $svc): void
{
    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    // fan-out 計畫表（每人推 or 拉）
    $planRows = '';
    foreach ($svc->fanoutPlan() as $p) {
        $badge = $p['is_celebrity']
            ? "<span class='pill pull'>拉 PULL</span>"
            : "<span class='pill push'>推 PUSH</span>";
        $planRows .= "<tr><td><code>{$h($p['user_id'])}</code></td>"
            . "<td>{$p['followers']} / {$p['threshold']}</td>"
            . "<td>$badge</td></tr>";
    }
    if ($planRows === '') {
        $planRows = "<tr><td colspan='3' style='color:#888'>尚無使用者，先按「載入示範資料」</td></tr>";
    }

    // 被選使用者的 feed
    $feedUser = trim((string) ($_GET['feed'] ?? ''));
    $feedBlock = '';
    if ($feedUser !== '') {
        $feed = $svc->buildFeed($feedUser);
        $items = '';
        foreach ($feed['items'] as $it) {
            $src = $it['source'] === 'push'
                ? "<span class='pill push'>推來的</span>"
                : "<span class='pill pull'>即時拉</span>";
            $items .= "<li>$src <code>{$h($it['author_id'])}</code>："
                . $h($it['text'])
                . " <span class='dim'>#{$it['seq']}</span></li>";
        }
        if ($items === '') {
            $items = "<li class='dim'>feed 為空（此人沒追蹤任何人、或追蹤對象尚未發文）</li>";
        }
        $st = $feed['stats'];
        $celebs = $st['pulled_from_celebrities'] ? implode(', ', $st['pulled_from_celebrities']) : '（無）';
        $feedBlock = "<div class='box'>"
            . "<span class='label'>📰 " . $h($feedUser) . " 的 News Feed（hybrid 合併排序）</span>"
            . "<ul class='feed'>$items</ul>"
            . "<p class='dim'>推來的 {$st['pushed_count']} 筆 ＋ 即時拉 {$st['pulled_count']} 筆"
            . "（拉自名人：" . $h($celebs) . "）；共追蹤 {$st['following_count']} 人。</p>"
            . "</div>";
    }

    // 使用者下拉選項
    $opts = '';
    foreach ($graph->allUsers() as $u) {
        $sel = ($u === $feedUser) ? ' selected' : '';
        $opts .= "<option value='{$h($u)}'$sel>{$h($u)}</option>";
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>News Feed 動態牆 · Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>
body{max-width:820px;margin:36px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:.4em}
code{background:#222;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse}td,th{border:1px solid #444;padding:8px;text-align:left}
.pill{display:inline-block;padding:1px 9px;border-radius:999px;font-size:.78rem;font-weight:700}
.pill.push{background:#13344d;color:#a5d8ff;border:1px solid #1971c2}
.pill.pull{background:#143d22;color:#b2f2bb;border:1px solid #2f9e44}
.feed{list-style:none;padding-left:0}.feed li{padding:6px 0;border-bottom:1px solid #333}
.dim{color:#888;font-size:.85rem}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
form{margin:.5em 0}input,select,button{font-size:.95rem}
fieldset{border:1px solid #333;border-radius:8px;margin:1em 0}
</style></head><body>
<h1>📰 News Feed 動態牆 · Demo</h1>
<p>聚焦 <strong>推 / 拉 / hybrid fan-out 取捨</strong>：一般使用者發文走<span class="pill push">推 PUSH</span>（寫入粉絲 feed 快取）；
名人（粉絲數 ≥ 門檻）走<span class="pill pull">拉 PULL</span>（讀時即時聚合）。讀 feed＝兩者合併排序。</p>

<form method="post" action="/api/seed">
  <button type="submit">① 一鍵載入示範資料（含名人 <code>star</code>）</button>
</form>

<fieldset><legend>② 建立追蹤關係（follower 追蹤 followee）</legend>
<form method="post" action="/api/follow" class="row">
  <input name="follower" placeholder="follower（粉絲）" required>
  <input name="followee" placeholder="followee（被追蹤）" required>
  <button type="submit">追蹤</button>
</form></fieldset>

<fieldset><legend>③ 發文（依作者是否名人自動推 or 拉）</legend>
<form method="post" action="/api/post" class="row">
  <input name="author" placeholder="author" required>
  <input name="text" placeholder="貼文內容" style="flex:1" required>
  <button type="submit">發文</button>
</form></fieldset>

<fieldset><legend>④ 讀某使用者的 feed</legend>
<form method="get" action="/" class="row">
  <select name="feed"><option value="">— 選使用者 —</option>$opts</select>
  <button type="submit">讀 feed</button>
</form></fieldset>

$feedBlock

<h2>每位使用者的 fan-out 策略（推 or 拉）</h2>
<p class="dim">名人門檻：粉絲數 ≥ <code>SocialGraph::CELEBRITY_THRESHOLD</code></p>
<table><tr><th>使用者</th><th>粉絲數 / 門檻</th><th>策略</th></tr>$planRows</table>

<p class="dim">※ 用 JSON 檔模擬 DB/Redis、同步函式模擬 Kafka 非同步 fan-out；
推/拉判定、fan-out on write、讀時拉名人、合併排序與來源標記為真實邏輯。</p>
</body></html>
HTML;
}
