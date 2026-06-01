<?php
declare(strict_types=1);

/**
 * 遊戲排行榜 Leaderboard Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8029 -t public
 *
 * 路由：
 *   GET  /                          測試頁（提交分數、看 Top-K、查我的名次+鄰居、切換日/週/總榜）
 *   POST /api/score                 提交/更新分數  { member, score, board }
 *   GET  /api/top?k=&board=         Top-K
 *   GET  /api/around/{member}?n=&board=   我的名次 + 上下鄰居視窗
 *
 * board 白名單：daily | weekly | all（各自是不同的 sorted set / 不同 key，示範時間分窗）。
 */

require __DIR__ . '/../src/SortedSet.php';

const BOARDS = ['daily', 'weekly', 'all'];
const BOARD_LABEL = ['daily' => '日榜', 'weekly' => '週榜', 'all' => '總榜'];
const DATA_DIR = __DIR__ . '/../data';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/score：提交 / 更新分數 ----
if ($method === 'POST' && $path === '/api/score') {
    [$member, $score, $board] = read_score_input();
    if ($member === '' || $score === null) {
        http_response_code(400);
        exit(json_out(['error' => 'member 與 score 為必填，score 須為數字']));
    }
    $set = new SortedSet(DATA_DIR, $board);
    // 在真實系統中，三個分窗榜會同時更新（玩家一局得分同時計入日/週/總）。
    // 這裡尊重 API 的 board 參數，只更新被指定的那個榜，方便逐一觀察。
    $rank = $set->zadd($member, $score);
    exit(json_out([
        'member' => $member,
        'score' => $score,
        'board' => $board,
        'rank' => $rank,        // 更新後即時名次（1 起算）
        'total' => $set->count(),
    ]));
}

// ---- GET /api/top?k=&board= ----
if ($method === 'GET' && $path === '/api/top') {
    $board = pick_board($_GET['board'] ?? 'all');
    $k = max(1, min(100, (int) ($_GET['k'] ?? 10)));
    $set = new SortedSet(DATA_DIR, $board);
    exit(json_out(['board' => $board, 'k' => $k, 'top' => $set->topK($k)]));
}

// ---- GET /api/around/{member}?n=&board= ----
if ($method === 'GET' && preg_match('#^/api/around/([^/]+)$#', $path, $m)) {
    $member = urldecode($m[1]);
    $board = pick_board($_GET['board'] ?? 'all');
    $n = max(1, min(50, (int) ($_GET['n'] ?? 2)));
    $set = new SortedSet(DATA_DIR, $board);
    $res = $set->around($member, $n);
    if ($res === null) {
        http_response_code(404);
        exit(json_out(['error' => "成員 {$member} 不在 {$board} 榜上"]));
    }
    exit(json_out(['board' => $board] + $res));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home();
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============

function pick_board(string $b): string
{
    $b = strtolower(trim($b));
    return in_array($b, BOARDS, true) ? $b : 'all';
}

/** 讀 score 提交輸入（支援表單與 JSON body）。 */
function read_score_input(): array
{
    $member = trim((string) ($_POST['member'] ?? ''));
    $scoreRaw = $_POST['score'] ?? null;
    $board = $_POST['board'] ?? 'all';
    if ($member === '' && ($raw = file_get_contents('php://input'))) {
        $j = json_decode((string) $raw, true);
        if (is_array($j)) {
            $member = trim((string) ($j['member'] ?? ''));
            $scoreRaw = $j['score'] ?? $scoreRaw;
            $board = $j['board'] ?? $board;
        }
    }
    $score = is_numeric($scoreRaw) ? (float) $scoreRaw : null;
    return [$member, $score, pick_board((string) $board)];
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function render_home(): void
{
    $board = pick_board($_GET['board'] ?? 'all');
    $boardLabel = BOARD_LABEL[$board];
    $set = new SortedSet(DATA_DIR, $board);
    $top = $set->topK(10);

    // Top-K 列
    $rows = '';
    foreach ($top as $r) {
        $medal = match ($r['rank']) {
            1 => '🥇', 2 => '🥈', 3 => '🥉', default => (string) $r['rank'],
        };
        $rows .= "<tr><td style='text-align:center'>{$medal}</td>"
            . '<td><code>' . htmlspecialchars((string) $r['member']) . '</code></td>'
            . '<td style="text-align:right">' . rtrim(rtrim(number_format($r['score'], 2), '0'), '.') . '</td></tr>';
    }
    if ($rows === '') {
        $rows = "<tr><td colspan='3' style='text-align:center;color:#888'>尚無資料，先在上方提交分數</td></tr>";
    }

    // 榜切換連結
    $tabs = '';
    foreach (BOARDS as $b) {
        $active = $b === $board ? 'background:#2f9e44;color:#fff' : '';
        $tabs .= "<a href='/?board={$b}' style='padding:6px 14px;border:1px solid #444;border-radius:6px;text-decoration:none;{$active}'>"
            . BOARD_LABEL[$b] . '</a> ';
    }

    // 我的名次 ± 鄰居（表單帶 member 時查詢）
    $aroundHtml = '';
    $qmember = trim((string) ($_GET['member'] ?? ''));
    if ($qmember !== '') {
        $res = $set->around($qmember, 2);
        if ($res === null) {
            $aroundHtml = "<div class='box warn'><span class='label'>查無此人</span>"
                . htmlspecialchars($qmember) . ' 不在' . BOARD_LABEL[$board] . '上。</div>';
        } else {
            $wrows = '';
            foreach ($res['window'] as $w) {
                $hl = $w['me'] ? 'background:#1b3a1b;font-weight:700' : '';
                $wrows .= "<tr style='{$hl}'><td style='text-align:center'>{$w['rank']}</td>"
                    . '<td><code>' . htmlspecialchars((string) $w['member']) . '</code>' . ($w['me'] ? ' ◀ 你' : '') . '</td>'
                    . '<td style="text-align:right">' . rtrim(rtrim(number_format($w['score'], 2), '0'), '.') . '</td></tr>';
            }
            $aroundHtml = "<div class='box tip'><span class='label'>你在" . BOARD_LABEL[$board]
                . "第 {$res['rank']} 名（共 {$res['total']} 人）</span>"
                . "<table><tr><th>名次</th><th>玩家</th><th>分數</th></tr>{$wrows}</table></div>";
        }
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>遊戲排行榜 · Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>body{max-width:760px;margin:40px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box.warn{border-left-color:#f0883e}
.box .label{font-weight:700;display:block;margin-bottom:6px}code{background:#222;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse;margin:.5em 0}td,th{border:1px solid #444;padding:8px;text-align:left}
form{margin:.5em 0}input,select{padding:6px}.tabs{margin:1em 0}</style>
</head><body>
<h1>🏆 遊戲排行榜 · Demo</h1>
<p>模擬 Redis ZSET 語意：提交分數即更新並重排；可看 Top-K、查自己名次±鄰居，並切換日/週/總三個分窗榜（不同 key）。</p>

<div class="tabs">榜別：{$tabs}（目前：<strong>{$boardLabel}</strong>）</div>

<h2>① 提交 / 更新分數</h2>
<form method="post" action="/api/score">
  <input type="hidden" name="board" value="{$board}">
  玩家 <input name="member" placeholder="alice" required>
  分數 <input name="score" type="number" step="any" placeholder="1000" required>
  <button type="submit">送出</button>
</form>
<p style="color:#888;font-size:.85rem">同一玩家再次送出會「更新」分數並重排（不是新增一筆）。送出後回 JSON 名次；回上一頁重整看榜。</p>

<h2>② Top 10（{$boardLabel}）</h2>
<table><tr><th style="width:60px">名次</th><th>玩家</th><th style="width:120px">分數</th></tr>{$rows}</table>

<h2>③ 我的名次 + 上下鄰居</h2>
<form method="get" action="/">
  <input type="hidden" name="board" value="{$board}">
  玩家 <input name="member" placeholder="alice" value="{$qmember}" required>
  <button type="submit">查名次</button>
</form>
{$aroundHtml}

<p style="color:#888;font-size:.85rem">※ 本 demo 用陣列排序模擬 ZSET：語意/Top-K/名次±鄰居/分窗為真，未達 Redis skiplist 的 O(log N)。</p>
</body></html>
HTML;
}
