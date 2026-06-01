<?php
declare(strict_types=1);

/**
 * Tinder / 配對系統 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8058 -t public
 *
 * 路由：
 *   GET  /                     測試頁（取候選堆疊、滑卡 like/pass、看配對）
 *   POST /api/seed             一鍵塞入示範使用者（台北市區，不同性別/年齡/偏好）
 *   GET  /api/candidates?user= 取某使用者的候選堆疊（geohash 召回 + 偏好過濾 + 去重 + 排序）
 *   POST /api/swipe            滑卡 { from, to, action: like|pass }；雙向 like 觸發 match
 *   GET  /api/matches?user=    某使用者的所有配對
 */

require __DIR__ . '/../src/GeoHash.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/MatchEngine.php';

$geo    = new GeoHash();
$store  = new Store(__DIR__ . '/../data');
$engine = new MatchEngine($geo, $store, 6);

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($path, '/') ?: '/';

// ---- POST /api/seed ----
if ($method === 'POST' && $path === '/api/seed') {
    $n = seed_demo_users($store);
    if (wants_html()) { header('Location: /?seeded=' . $n); exit; }
    exit(json_out(['ok' => true, 'seeded' => $n, 'total' => count($store->users())]));
}

// ---- GET /api/candidates ----
if ($method === 'GET' && $path === '/api/candidates') {
    $user = (string)($_GET['user'] ?? '');
    $res  = $engine->candidates($user, max(1, (int)($_GET['limit'] ?? 20)));
    exit(json_out([
        'user'            => $user,
        'center_geohash'  => $res['center_geohash'],
        'buckets_scanned' => $res['buckets_scanned'],
        'recalled'        => $res['recalled'],
        'count'           => count($res['candidates']),
        'candidates'      => $res['candidates'],
    ]));
}

// ---- POST /api/swipe ----
if ($method === 'POST' && $path === '/api/swipe') {
    $in     = read_input();
    $from   = trim((string)($in['from'] ?? ''));
    $to     = trim((string)($in['to'] ?? ''));
    $action = trim((string)($in['action'] ?? 'like'));
    $r      = $engine->swipe($from, $to, $action);
    if (wants_html()) {
        $q = $r['matched'] ? 'match=' . urlencode($to) : 'swiped=' . urlencode($to . ':' . $action);
        header('Location: /?user=' . urlencode($from) . '&' . $q);
        exit;
    }
    if (!$r['ok']) {
        http_response_code(400);
    }
    exit(json_out($r));
}

// ---- GET /api/matches ----
if ($method === 'GET' && $path === '/api/matches') {
    $user = (string)($_GET['user'] ?? '');
    exit(json_out(['user' => $user, 'matches' => $store->matchesOf($user)]));
}

// ---- GET / ----
if ($method === 'GET' && $path === '/') {
    render_home($store, $engine);
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

/**
 * 塞一批台北市區使用者：不同性別 / 年齡 / 偏好 / 位置，
 * 並刻意安排幾組「互相符合偏好」的人，方便演示雙向 like → match。
 */
function seed_demo_users(Store $store): int
{
    // id, name, gender, age, lat, lng, pref_gender, age_min, age_max, max_km
    $users = [
        ['u_alice', 'Alice', 'F', 28, 25.0339, 121.5645, 'M', 25, 35, 10],
        ['u_bob',   'Bob',   'M', 30, 25.0335, 121.5650, 'F', 24, 32, 10],
        ['u_carol', 'Carol', 'F', 26, 25.0340, 121.5640, 'M', 27, 33, 10],
        ['u_dave',  'Dave',  'M', 31, 25.0330, 121.5660, 'F', 25, 30, 10],
        ['u_eve',   'Eve',   'F', 24, 25.0400, 121.5600, 'M', 28, 40, 10],
        ['u_frank', 'Frank', 'M', 45, 25.0345, 121.5648, 'F', 22, 28, 10], // 年齡偏好不對盤
        ['u_grace', 'Grace', 'F', 29, 25.2000, 121.7000, 'M', 25, 35, 10], // 太遠（基隆方向）
        ['u_henry', 'Henry', 'M', 27, 25.0338, 121.5644, 'F', 24, 30, 10],
    ];
    $n = 0;
    foreach ($users as $u) {
        $store->putUser($u[0], $u[1], [
            'gender'      => $u[2],
            'age'         => $u[3],
            'lat'         => $u[4],
            'lng'         => $u[5],
            'pref_gender' => $u[6],
            'age_min'     => $u[7],
            'age_max'     => $u[8],
            'max_km'      => $u[9],
        ]);
        $n++;
    }
    return $n;
}

function render_home(Store $store, MatchEngine $engine): void
{
    $seeded = $_GET['seeded'] ?? null;
    $match  = $_GET['match']  ?? null;
    $swiped = $_GET['swiped'] ?? null;
    $cur    = (string)($_GET['user'] ?? 'u_alice');
    $users  = $store->users();
    $userCount = count($users);

    // 使用者下拉
    $opts = '';
    foreach ($users as $u) {
        $sel = $u['id'] === $cur ? ' selected' : '';
        $opts .= '<option value="' . htmlspecialchars($u['id']) . '"' . $sel . '>'
            . htmlspecialchars($u['name'] . '（' . $u['gender'] . '/' . $u['age'] . '）')
            . '</option>';
    }

    // 使用者總表
    $rows = '';
    foreach ($users as $u) {
        $rows .= '<tr><td><code>' . htmlspecialchars($u['id']) . '</code></td>'
            . '<td>' . htmlspecialchars($u['name']) . '</td>'
            . '<td>' . htmlspecialchars($u['gender']) . '/' . (int)$u['age'] . '</td>'
            . '<td>想看 ' . htmlspecialchars($u['pref_gender']) . '，'
            . (int)$u['age_min'] . '-' . (int)$u['age_max'] . ' 歲，≤' . htmlspecialchars((string)$u['max_km']) . 'km</td>'
            . '<td>' . htmlspecialchars((string)$u['lat']) . ', ' . htmlspecialchars((string)$u['lng']) . '</td></tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="5" style="color:#888">尚無使用者，先按「塞入示範使用者」。</td></tr>';
    }

    // 候選堆疊（含滑卡按鈕）
    $stack = '';
    if (isset($users[$cur])) {
        $res = $engine->candidates($cur, 20);
        foreach ($res['candidates'] as $c) {
            $flag = $c['likes_me'] ? ' <span class="hot">❤ 已喜歡你</span>' : '';
            $stack .= '<tr><td>' . htmlspecialchars($c['name']) . $flag . '</td>'
                . '<td>' . htmlspecialchars($c['gender']) . '/' . (int)$c['age'] . '</td>'
                . '<td>' . htmlspecialchars((string)$c['distance_km']) . ' km</td>'
                . '<td>'
                . form_btn($cur, $c['id'], 'like', '❤ Like')
                . form_btn($cur, $c['id'], 'pass', '✕ Pass')
                . '</td></tr>';
        }
        if ($stack === '') {
            $stack = '<tr><td colspan="4" style="color:#888">沒有候選了（都滑過了，或無人符合雙向偏好）。</td></tr>';
        }
        $center  = htmlspecialchars($res['center_geohash']);
        $buckets = htmlspecialchars(implode(' ', $res['buckets_scanned']));
        $recall  = (int)$res['recalled'];
    } else {
        $stack = '<tr><td colspan="4" style="color:#888">先塞示範使用者。</td></tr>';
        $center = '-'; $buckets = '-'; $recall = 0;
    }

    // 配對清單
    $mrows = '';
    foreach ($store->matchesOf($cur) as $m) {
        $other = $store->user($m['with']);
        $mrows .= '<tr><td>💞 ' . htmlspecialchars($other['name'] ?? $m['with'])
            . '</td><td><code>' . htmlspecialchars($m['pair']) . '</code></td></tr>';
    }
    if ($mrows === '') {
        $mrows = '<tr><td colspan="2" style="color:#888">尚無配對（要雙方互相 Like 才成立）。</td></tr>';
    }

    $banner = '';
    if ($seeded !== null) {
        $banner = "<div class='box tip'><span class='label'>已塞入 " . (int)$seeded . " 位示範使用者</span>下方選一位使用者，看他的候選堆疊並滑卡。</div>";
    } elseif ($match !== null) {
        $banner = "<div class='box hot'><span class='label'>🎉 配對成立！</span>你和 " . htmlspecialchars((string)$match) . " 互相喜歡 —— 現在可以聊天了。</div>";
    } elseif ($swiped !== null) {
        $banner = "<div class='box tip'><span class='label'>已滑卡</span>" . htmlspecialchars((string)$swiped) . "（已看過的對方不會再出現）。</div>";
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tinder 配對 · Matching Demo</title>
<style>
:root{color-scheme:dark}
body{max-width:920px;margin:32px auto;padding:0 16px;background:#0f1419;color:#e6edf3;
 font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.6}
h1{font-size:1.6rem}h2{font-size:1.15rem;border-bottom:1px solid #2a3540;padding-bottom:.3em;margin-top:1.6em}
a{color:#58a6ff}code{background:#161b22;padding:2px 6px;border-radius:5px;color:#7ee787;font-size:.88em}
.box{border-left:4px solid #7ee787;background:#1a2027;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box.hot{border-left-color:#ff7b9c}
.box .label{font-weight:700;display:block;margin-bottom:.2em}
.hot{color:#ff7b9c;font-weight:700}
table{width:100%;border-collapse:collapse;margin:.8em 0;font-size:.9rem}
td,th{border:1px solid #2a3540;padding:7px 10px;text-align:left;vertical-align:top}
th{background:#1a2027;color:#58a6ff}
input,select,button{background:#161b22;color:#e6edf3;border:1px solid #2a3540;border-radius:6px;padding:6px 10px;font-size:.9rem}
button{background:#1f6feb;border-color:#1f6feb;cursor:pointer}button:hover{background:#388bfd}
button.pass{background:#30363d;border-color:#30363d}
form{display:inline}fieldset{border:1px solid #2a3540;border-radius:8px;margin:1em 0}
.muted{color:#9aa7b3;font-size:.85rem}
.kv{font-size:.85rem;color:#9aa7b3;margin:.4em 0}
</style></head><body>
<h1>💘 Tinder 配對 · Matching Demo</h1>
<p class="muted">geohash 召回附近的人 → 雙向偏好過濾 → 去重（看過不再出現）→ 排序 → 滑卡 → 雙向 like 觸發配對。聚焦配對核心，非 CRUD。</p>
$banner

<fieldset><legend>① 建立資料</legend>
  <form method="post" action="/api/seed"><button type="submit">塞入示範使用者（台北市區 8 位）</button>
    <span class="muted">目前共 $userCount 位</span></form>
</fieldset>

<fieldset><legend>② 選擇使用者，查看候選堆疊</legend>
  <form method="get" action="/">
    <select name="user" onchange="this.form.submit()">$opts</select>
    <button type="submit">查候選</button>
    <a href="/api/candidates?user=$cur" target="_blank" class="muted">（看 JSON）</a>
  </form>
  <p class="kv">中心 geohash：<code>$center</code>　掃描桶（中心格+8 鄰格）：<code>$buckets</code>　召回 $recall 人</p>
  <table><tr><th>對象</th><th>性別/年齡</th><th>距離</th><th>滑卡</th></tr>$stack</table>
  <p class="muted">排序：「❤ 已喜歡你」的排最前（互相喜歡機率最高），其次距離近者優先。對其中一人 Like，若對方也曾 Like 你 → 立即配對。</p>
</fieldset>

<fieldset><legend>③ <code>$cur</code> 的配對（<a href="/api/matches?user=$cur" target="_blank">JSON</a>）</legend>
  <table><tr><th>配對對象</th><th>pair key</th></tr>$mrows</table>
</fieldset>

<h2>所有使用者</h2>
<table><tr><th>id</th><th>名稱</th><th>性別/年齡</th><th>偏好</th><th>座標</th></tr>$rows</table>

<p class="muted">※ 本機 PHP 未載入 mbstring，程式全程使用原生字串函式。地理位置與滑卡為模擬；geohash 候選召回、雙向偏好過濾、配對偵測（互相 like）、去重皆為真實可執行邏輯。</p>
</body></html>
HTML;
}

/** 產生一個內嵌的滑卡表單按鈕 */
function form_btn(string $from, string $to, string $action, string $label): string
{
    $cls = $action === 'pass' ? ' class="pass"' : '';
    return '<form method="post" action="/api/swipe">'
        . '<input type="hidden" name="from" value="' . htmlspecialchars($from) . '">'
        . '<input type="hidden" name="to" value="' . htmlspecialchars($to) . '">'
        . '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">'
        . '<button type="submit"' . $cls . '>' . $label . '</button></form> ';
}
