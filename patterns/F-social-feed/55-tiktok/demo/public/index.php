<?php
declare(strict_types=1);

/**
 * TikTok / Netflix「For You」推薦流 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8055 -t public
 *
 * 聚焦系統設計考點（綜合 YouTube + 推薦 + Feed）：
 *   召回 → 排序 → 去重 → Top-N + 記錄觀看（session 不重複） + 預載下一頁
 *   看一支 → 更新興趣訊號（看完/讚） → 下次推得更準（回饋迴路）
 *
 * 路由：
 *   GET  /                              測試頁（取頁 / 看影片 / 看狀態 一站式）
 *   GET  /api/feed?user=U               取某使用者一頁 For You（召回→排序→去重→Top-N + 預載）
 *   POST /api/watch                     看一支 { user, video, action } → 更新興趣（回饋迴路）
 *   GET  /api/state?user=U              看使用者目前興趣向量 + 已看數
 *   POST /api/seed                      一鍵建立示範影片 + 給 alice 初始興趣
 *   POST /api/reset?user=U              清掉本輪已看（重新開始一輪）
 */

require __DIR__ . '/../src/VideoStore.php';
require __DIR__ . '/../src/Recommender.php';
require __DIR__ . '/../src/FeedSession.php';

$dataDir = __DIR__ . '/../data';
$store = new VideoStore($dataDir);
$recommender = new Recommender($store);
$session = new FeedSession($store, $recommender);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

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

// ---- GET /api/feed?user=U：取一頁 For You ----
if ($method === 'GET' && $path === '/api/feed') {
    $user = trim((string) ($_GET['user'] ?? ''));
    if ($user === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user 必填']));
    }
    exit(json_out($session->feed($user)));
}

// ---- POST /api/watch：看一支 → 更新興趣 ----
if ($method === 'POST' && $path === '/api/watch') {
    $user = input('user');
    $video = input('video');
    $action = input('action') ?: 'completed';
    if ($user === '' || $video === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user / video 必填']));
    }
    $result = $session->watch($user, $video, $action);
    if (wants_html()) { header('Location: /?user=' . rawurlencode($user)); exit; }
    exit(json_out($result));
}

// ---- GET /api/state?user=U ----
if ($method === 'GET' && $path === '/api/state') {
    $user = trim((string) ($_GET['user'] ?? ''));
    if ($user === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user 必填']));
    }
    exit(json_out($session->state($user)));
}

// ---- POST /api/reset?user=U ----
if ($method === 'POST' && $path === '/api/reset') {
    $user = trim((string) ($_GET['user'] ?? input('user')));
    if ($user === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user 必填']));
    }
    $session->resetSession($user);
    if (wants_html()) { header('Location: /?user=' . rawurlencode($user)); exit; }
    exit(json_out(['ok' => true, 'note' => "已清掉 $user 本輪已看記錄"]));
}

// ---- POST /api/seed：示範影片 + alice 初始興趣 ----
if ($method === 'POST' && $path === '/api/seed') {
    // 影片：id => [標籤], 熱度, 上架小時
    $store->addVideo('v_dance1', ['dance', 'music'], 120, 2);
    $store->addVideo('v_dance2', ['dance', 'trend'], 80, 30);
    $store->addVideo('v_cook1', ['cooking', 'food'], 200, 5);
    $store->addVideo('v_cook2', ['cooking', 'diy'], 40, 1);
    $store->addVideo('v_cat1', ['cat', 'pet', 'funny'], 500, 10);
    $store->addVideo('v_cat2', ['cat', 'pet'], 90, 60);
    $store->addVideo('v_game1', ['gaming', 'esports'], 300, 3);
    $store->addVideo('v_game2', ['gaming', 'funny'], 60, 48);
    $store->addVideo('v_news1', ['news', 'tech'], 150, 1);
    $store->addVideo('v_diy1', ['diy', 'craft'], 70, 20);
    $store->addVideo('v_music1', ['music', 'trend'], 220, 4);
    $store->addVideo('v_travel1', ['travel', 'vlog'], 110, 8);

    // alice 對 dance / music 有偏好（cooking 略有）→ 召回/排序會偏這幾類
    $store->setInterests('alice', ['dance' => 1.5, 'music' => 1.0, 'cooking' => 0.4]);
    // bob 沒有任何興趣訊號 → 示範冷啟動（靠熱門 + 探索）
    $store->getUser('bob');

    if (wants_html()) { header('Location: /?user=alice'); exit; }
    exit(json_out(['ok' => true, 'note' => 'seed 完成：12 支影片，alice 有興趣、bob 冷啟動']));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($store, $session);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 測試頁 ============
function render_home(VideoStore $store, FeedSession $session): void
{
    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $user = trim((string) ($_GET['user'] ?? ''));
    $block = '';

    if ($user !== '') {
        $result = $session->feed($user);
        $state = $session->state($user);

        // 興趣向量
        arsort($state['interests']);
        $interestStr = '';
        foreach ($state['interests'] as $tag => $score) {
            $interestStr .= "<span class='pill interest'>{$h((string)$tag)} {$score}</span> ";
        }
        if ($interestStr === '') {
            $interestStr = "<span class='dim'>（尚無興趣訊號 → 冷啟動，靠熱門 + 探索）</span>";
        }

        // 本頁
        $items = '';
        foreach ($result['page'] as $v) {
            $explore = !empty($v['_explore']) ? "<span class='pill explore'>探索</span>" : '';
            $sc = $v['_score'] ?? ['total' => '-', 'relevance' => '-', 'popularity' => '-', 'freshness' => '-'];
            $tags = implode(' ', array_map(fn($t) => "#$t", $v['tags']));
            $items .= "<li>"
                . "<form method='post' action='/api/watch' style='display:inline'>"
                . "<input type='hidden' name='user' value='{$h($user)}'>"
                . "<input type='hidden' name='video' value='{$h($v['id'])}'>"
                . "<code>{$h($v['id'])}</code> $explore "
                . "<span class='dim'>{$h($tags)}</span> "
                . "<span class='score'>分 {$sc['total']}</span> "
                . "<span class='dim'>(相符 {$sc['relevance']} · 熱度 {$sc['popularity']} · 新鮮 {$sc['freshness']})</span> "
                . "<button name='action' value='completed'>看完</button>"
                . "<button name='action' value='liked'>讚</button>"
                . "<button name='action' value='skipped'>滑掉</button>"
                . "</form></li>";
        }
        if ($items === '') {
            $items = "<li class='dim'>沒有候選了（本輪都看過了？按「重新一輪」清掉已看）</li>";
        }

        // 預載下一頁
        $nextItems = '';
        foreach ($result['next_page'] as $v) {
            $tags = implode(' ', array_map(fn($t) => "#$t", $v['tags']));
            $nextItems .= "<li><code>{$h($v['id'])}</code> <span class='dim'>{$h($tags)}</span></li>";
        }
        if ($nextItems === '') {
            $nextItems = "<li class='dim'>（無）</li>";
        }

        $d = $result['debug'];
        $coldNote = $d['cold_start']
            ? "<span class='pill explore'>冷啟動</span>（無興趣訊號 → 靠熱門路 + 探索）"
            : "";
        $recallNote = "興趣路 " . count($d['recall_breakdown']['interest']) . " · "
            . "熱門路 " . count($d['recall_breakdown']['popular']) . " · "
            . "協同路 " . count($d['recall_breakdown']['collaborative'])
            . " → 候選池 {$d['candidate_count']} 支（已看 {$d['watched_count']} 支將被去重）";

        $block = "<div class='box'>"
            . "<span class='label'>🎯 " . $h($user) . " 的興趣向量</span>$interestStr</div>"
            . "<div class='box'><span class='label'>📥 召回（多路）</span>$coldNote<p class='dim'>$recallNote</p></div>"
            . "<div class='box'><span class='label'>🎬 For You 這一頁（召回→排序→去重→Top-N）</span>"
            . "<ul class='feed'>$items</ul>"
            . "<p class='dim'>點「看完 / 讚」會把該影片標籤加進興趣（回饋迴路）→ 取下一頁時這類分數更高、且這支被去重不再出現。</p></div>"
            . "<div class='box'><span class='label'>⏭️ 預載的下一頁（前端可先抓，捲到底瞬間顯示）</span>"
            . "<ul class='feed'>$nextItems</ul></div>"
            . "<form method='post' action='/api/reset?user=" . rawurlencode($user) . "'>"
            . "<button type='submit'>🔄 重新一輪（清掉本輪已看，興趣保留）</button></form>";
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TikTok For You 推薦流 · Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>
body{max-width:860px;margin:36px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:.4em}
code{background:#222;padding:2px 6px;border-radius:4px}
.pill{display:inline-block;padding:1px 9px;border-radius:999px;font-size:.78rem;font-weight:700;margin:1px}
.pill.interest{background:#143d22;color:#b2f2bb;border:1px solid #2f9e44}
.pill.explore{background:#3d2a14;color:#ffd8a8;border:1px solid #e8590c}
.score{color:#a5d8ff;font-weight:700}
.feed{list-style:none;padding-left:0}.feed li{padding:8px 0;border-bottom:1px solid #333}
.feed button{font-size:.8rem;padding:2px 8px;margin-left:4px}
.dim{color:#888;font-size:.85rem}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
form{margin:.5em 0}input,select,button{font-size:.95rem}
fieldset{border:1px solid #333;border-radius:8px;margin:1em 0}
</style></head><body>
<h1>🎬 TikTok「For You」推薦流 · Demo</h1>
<p>聚焦推薦核心：<strong>召回 → 排序 → 去重 → Top-N</strong>，看一支 → <strong>回饋迴路</strong>更新興趣 → 下次推得更準。
綜合 YouTube（轉碼分發）＋推薦排序＋Feed 分頁三題。</p>

<form method="post" action="/api/seed">
  <button type="submit">① 一鍵載入示範影片（含 <code>alice</code> 有興趣、<code>bob</code> 冷啟動）</button>
</form>

<fieldset><legend>② 取某使用者的 For You 流</legend>
<form method="get" action="/" class="row">
  <input name="user" placeholder="user（試 alice 或 bob）" value="{$h($user)}" required>
  <button type="submit">取 For You</button>
</form></fieldset>

$block

<p class="dim">建議流程：①載入示範資料 → ② 取 <code>alice</code> 的 For You（dance/music 排前面）→
對某支按「看完 / 讚」→ 再「取 For You」看那支被去重、同類分數變更高（更貼興趣）。
換成 <code>bob</code> 看冷啟動（靠熱門 + 探索）。</p>
<p class="dim">※ 用 JSON 檔模擬影片庫與使用者特徵；用加權線性分數模擬排序模型；無真影片、無真模型。
但召回（多路）→ 排序（特徵打分）→ 去重（session 不重複）→ 興趣回饋迴路 → 預載下一頁，皆為真實可執行邏輯。</p>
</body></html>
HTML;
}
