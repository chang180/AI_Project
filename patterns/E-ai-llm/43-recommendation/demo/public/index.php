<?php
declare(strict_types=1);

/**
 * 推薦系統 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8043 -t public
 *
 * 路由：
 *   GET  /                      測試頁（列 user 與互動；對 user 取推薦看「召回→排序」兩階段；新 user 看冷啟動）
 *   POST /api/interaction       新增互動 { user, item, type }
 *   GET  /api/recommend?user=   對某 user 取推薦（含召回候選與排序明細）
 *   GET  /api/state             傾印目前的 item / 互動 / item-item 相似度
 */

require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Recommender.php';

$store = new Store(__DIR__ . '/../data');
$rec = new Recommender($store);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/interaction ----
if ($method === 'POST' && $path === '/api/interaction') {
    $in = read_input();
    $user = trim((string) ($in['user'] ?? ''));
    $item = trim((string) ($in['item'] ?? ''));
    $type = trim((string) ($in['type'] ?? 'view'));
    if ($user === '' || $item === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user 與 item 必填']));
    }
    if (!in_array($type, ['view', 'click', 'buy'], true)) {
        $type = 'view';
    }
    if ($store->item($item) === null) {
        http_response_code(404);
        exit(json_out(['error' => "item $item 不存在"]));
    }
    $store->addInteraction($user, $item, $type);
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        header('Location: /?user=' . urlencode($user));
        exit;
    }
    exit(json_out(['ok' => true, 'user' => $user, 'item' => $item, 'type' => $type]));
}

// ---- GET /api/recommend?user= ----
if ($method === 'GET' && $path === '/api/recommend') {
    $user = trim((string) ($_GET['user'] ?? ''));
    if ($user === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user 必填']));
    }
    $n = max(1, min(10, (int) ($_GET['n'] ?? 5)));
    exit(json_out(['user' => $user] + $rec->recommend($user, $n)));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out([
        'items'           => $store->items(),
        'events'          => $store->events(),
        'item_similarity' => $rec->itemItemSimilarity(),
    ]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($store, $rec);
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
    if ($raw === '') {
        return [];
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function render_home(Store $store, Recommender $rec): void
{
    $users = $store->users();
    $items = $store->items();
    $sel = trim((string) ($_GET['user'] ?? ($users[0] ?? 'u1')));

    // 互動列表（依 user 分組）
    $byUser = [];
    foreach ($store->events() as $ev) {
        $byUser[$ev['user']][] = $ev;
    }
    $userRows = '';
    foreach ($users as $u) {
        $chips = '';
        foreach (($byUser[$u] ?? []) as $ev) {
            $t = $ev['type'];
            $title = $items[$ev['item']]['title'] ?? $ev['item'];
            $chips .= "<span class='chip t-$t'>" . h($title) . " · $t</span> ";
        }
        $active = $u === $sel ? " style='background:#243' " : '';
        $userRows .= "<tr$active><td><a href='/?user=" . h($u) . "'><code>" . h($u) . "</code></a></td><td>$chips</td></tr>";
    }

    // 推薦結果（兩階段）
    $r = $rec->recommend($sel, 5);
    $isNew = !in_array($sel, $users, true);

    if ($r['cold_start']) {
        $stageHtml = "<div class='box warn'><span class='label'>冷啟動（此 user 無互動）</span>"
            . "無法個人化召回，退回 <strong>熱門 item（popularity fallback）</strong>。</div>";
        $stageHtml .= rank_table($r['topN'], '熱門 Top-5');
    } else {
        // 召回明細
        $cfRows = '';
        foreach ($r['recall']['cf'] as $item => $sc) {
            $cfRows .= "<tr><td><code>" . h((string) $item) . "</code> " . h($items[$item]['title'] ?? '') . "</td><td>" . round($sc, 3) . "</td></tr>";
        }
        $annRows = '';
        foreach ($r['recall']['ann'] as $item => $sc) {
            $annRows .= "<tr><td><code>" . h((string) $item) . "</code> " . h($items[$item]['title'] ?? '') . "</td><td>" . round($sc, 3) . "</td></tr>";
        }
        $merged = implode(', ', array_map(fn($i) => $i, $r['recall']['merged']));

        $stageHtml = "<h3>① 召回 Candidate Generation</h3>"
            . "<div class='cols'>"
            . "<div><h4>協同過濾 (item-based CF)</h4><table><tr><th>候選 item</th><th>召回分</th></tr>" . ($cfRows ?: "<tr><td colspan=2>—</td></tr>") . "</table></div>"
            . "<div><h4>Embedding ANN</h4><table><tr><th>候選 item</th><th>內積</th></tr>" . ($annRows ?: "<tr><td colspan=2>—</td></tr>") . "</table></div>"
            . "</div>"
            . "<p>聯集候選（已排除使用者互動過的 item）：<code>" . h($merged ?: '—') . "</code></p>"
            . "<h3>② 排序 Ranking（多特徵加權 → Top-5）</h3>"
            . rank_table($r['topN'], '');
    }

    // item 下拉（給互動表單）
    $itemOpts = '';
    foreach ($items as $id => $m) {
        $itemOpts .= "<option value='" . h((string) $id) . "'>" . h((string) $id) . " · " . h($m['title']) . "</option>";
    }

    $newBadge = $isNew ? " <span class='chip t-buy'>新 user → 冷啟動</span>" : '';
    $selH = h($sel); // 預先轉義，供 heredoc 直接內插（heredoc 不會執行函式呼叫）

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>推薦系統 · Demo</title>
<style>
body{max-width:900px;margin:32px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#0f1115;color:#e6e6e6}
h1{font-size:1.5rem}h3{margin-top:1.4em;border-left:4px solid #4dabf7;padding-left:8px}h4{margin:.6em 0}
a{color:#74c0fc}code{background:#1b1f27;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse;margin:.4em 0}td,th{border:1px solid #333;padding:7px;text-align:left;font-size:.92rem}
th{background:#1b1f27}
.box{border-left:4px solid #7ee787;background:#161a22;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box.warn{border-color:#ffd43b}.box .label{font-weight:700;display:block;margin-bottom:4px}
.chip{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.78rem;background:#243047;margin:2px 0}
.chip.t-buy{background:#2b8a3e}.chip.t-click{background:#1864ab}.chip.t-view{background:#495057}
.cols{display:flex;gap:18px;flex-wrap:wrap}.cols>div{flex:1;min-width:280px}
form{background:#161a22;padding:12px 16px;border-radius:8px;margin:1em 0}
input,select,button{padding:6px 8px;border-radius:6px;border:1px solid #444;background:#0f1115;color:#e6e6e6}
button{background:#1864ab;cursor:pointer;border-color:#1864ab}
.bar{display:flex;gap:6px;align-items:center}.bar .fill{height:10px;background:#4dabf7;border-radius:3px}
</style></head><body>
<h1>🎯 推薦系統 · Demo</h1>
<p>兩階段架構：<strong>召回（協同過濾 + Embedding ANN）→ 排序（多特徵加權 Top-N）</strong>；新 user 走冷啟動熱門。</p>

<h3>使用者與互動記錄</h3>
<table><tr><th>user</th><th>互動（item · 類型）</th></tr>$userRows</table>

<form method="get" action="/">
  <div class="bar">看推薦的 user：
  <input name="user" value="$selH" placeholder="u1 或輸入新 user（如 newbie）看冷啟動" style="width:320px">
  <button type="submit">取得推薦</button></div>
</form>

<h2>對 <code>$selH</code> 的推薦$newBadge</h2>
$stageHtml

<h3>新增互動（會改變召回/排序與熱門度）</h3>
<form method="post" action="/api/interaction">
  <div class="bar">
  user <input name="user" value="$selH" style="width:120px">
  item <select name="item">$itemOpts</select>
  type <select name="type"><option value="view">view</option><option value="click">click</option><option value="buy">buy</option></select>
  <button type="submit">送出互動</button></div>
</form>

<p style="color:#888;font-size:.82rem">※ 協同過濾 / 兩階段召回排序 / 冷啟動為真實邏輯；embedding 用 category 示意、ANN 為暴力全掃（非真模型/真索引）。
另見 <a href="/api/recommend?user=$selH" target="_blank">/api/recommend</a> · <a href="/api/state" target="_blank">/api/state</a></p>
</body></html>
HTML;
}

/** 排序結果表（含各特徵分與分數條） */
function rank_table(array $rows, string $caption): string
{
    $maxScore = 0.0;
    foreach ($rows as $r) {
        $maxScore = max($maxScore, (float) $r['score']);
    }
    $maxScore = $maxScore ?: 1.0;
    $out = $caption !== '' ? "<h4>" . h($caption) . "</h4>" : '';
    $out .= "<table><tr><th>#</th><th>item</th><th>類別</th><th>總分</th><th>特徵明細</th></tr>";
    $i = 1;
    foreach ($rows as $r) {
        $w = (int) round(120 * (float) $r['score'] / $maxScore);
        $feat = [];
        foreach (($r['features'] ?? []) as $k => $v) {
            $feat[] = "$k=$v";
        }
        $featStr = h(implode('  ', $feat));
        $bar = "<div class='bar'><div class='fill' style='width:{$w}px'></div>&nbsp;" . h((string) $r['score']) . "</div>";
        $out .= "<tr><td>$i</td><td><code>" . h((string) $r['item']) . "</code> " . h((string) $r['title'])
            . "</td><td>" . h((string) $r['category']) . "</td><td>$bar</td><td><small>$featStr</small></td></tr>";
        $i++;
    }
    $out .= "</table>";
    return $out;
}
