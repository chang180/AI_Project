<?php
declare(strict_types=1);

/**
 * ChatGPT Tasks Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8011 -t public
 *
 * 核心流程：建立任務 → scheduler tick（模擬時間前進，掃出到期任務入列）
 *          → run worker（取出執行、狀態轉移、冪等/重試）→ 看結果與通知。
 *
 * 路由：
 *   GET  /                  測試頁（建立任務、tick、run、檢視狀態）
 *   POST /api/tasks         建立任務 { name, type, interval_sec|delay_sec, prompt, force_fail }
 *   POST /api/tick          推進邏輯時間並掃出到期任務入列  { now }
 *   POST /api/run           執行 worker（消化 QUEUED 的 run）
 *   POST /api/reset         清空所有資料
 *   GET  /api/state         以 JSON 回傳目前任務 / runs / 通知 / 邏輯時間
 */

require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Scheduler.php';
require __DIR__ . '/../src/TaskQueue.php';
require __DIR__ . '/../src/Worker.php';

$dataDir = __DIR__ . '/../data';
$store = new Store($dataDir);
$queue = new TaskQueue($store);
$scheduler = new Scheduler($store, $queue);
$worker = new Worker($store, $queue, maxRetry: 2);
$clockFile = $dataDir . '/clock.txt';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// 邏輯時鐘：以檔案保存「目前模擬時間」，預設為真實 now。tick 時可手動前進。
$logicalNow = file_exists($clockFile) ? (int) file_get_contents($clockFile) : time();

// ---- POST /api/tasks：建立任務 ----
if ($method === 'POST' && $path === '/api/tasks') {
    $in = read_input();
    $name = trim((string) ($in['name'] ?? ''));
    $type = ($in['type'] ?? 'once') === 'interval' ? 'interval' : 'once';
    $prompt = trim((string) ($in['prompt'] ?? '每天早上摘要科技新聞'));
    if ($name === '') {
        http_response_code(400);
        exit(json_out(['error' => '請提供任務名稱 name']));
    }

    $id = $store->nextTaskId();
    if ($type === 'interval') {
        $interval = max(1, (int) ($in['interval_sec'] ?? 10));
        $task = [
            'id' => $id,
            'name' => $name,
            'type' => 'interval',
            'interval_sec' => $interval,
            'prompt' => $prompt,
            'status' => 'SCHEDULED',
            'next_run_at' => $logicalNow + $interval, // 首次在一個間隔後
            'last_fired_at' => null,
            'force_fail' => !empty($in['force_fail']),
            'notify_channel' => 'inbox',
            'tz' => 'UTC', // 時區欄位：真實系統依使用者時區換算 next_run_at
            'created_at' => gmdate('c'),
        ];
    } else {
        $delay = max(0, (int) ($in['delay_sec'] ?? 5));
        $task = [
            'id' => $id,
            'name' => $name,
            'type' => 'once',
            'interval_sec' => 0,
            'prompt' => $prompt,
            'status' => 'SCHEDULED',
            'next_run_at' => $logicalNow + $delay, // 一次性：now + delay 後到期
            'last_fired_at' => null,
            'force_fail' => !empty($in['force_fail']),
            'notify_channel' => 'inbox',
            'tz' => 'UTC',
            'created_at' => gmdate('c'),
        ];
    }
    $store->putTask($task);

    if (wants_html()) {
        header('Location: /');
        exit;
    }
    exit(json_out(['created' => $task]));
}

// ---- POST /api/tick：推進邏輯時間 + scheduler 掃出到期任務 ----
if ($method === 'POST' && $path === '/api/tick') {
    $in = read_input();
    // 預設前進 10 秒；可帶 now 指定絕對時間，或帶 advance 指定前進秒數
    if (isset($in['now'])) {
        $newNow = (int) $in['now'];
    } else {
        $advance = (int) ($in['advance'] ?? 10);
        $newNow = $logicalNow + max(1, $advance);
    }
    file_put_contents($clockFile, (string) $newNow, LOCK_EX);

    $fired = $scheduler->tick($newNow);

    if (wants_html()) {
        header('Location: /');
        exit;
    }
    exit(json_out(['logical_now' => $newNow, 'fired' => $fired]));
}

// ---- POST /api/run：執行 worker ----
if ($method === 'POST' && $path === '/api/run') {
    $report = $worker->runOnce();
    if (wants_html()) {
        header('Location: /');
        exit;
    }
    exit(json_out(['ran' => $report]));
}

// ---- POST /api/reset：清空 ----
if ($method === 'POST' && $path === '/api/reset') {
    $store->reset();
    @file_put_contents($clockFile, (string) time(), LOCK_EX);
    if (wants_html()) {
        header('Location: /');
        exit;
    }
    exit(json_out(['reset' => true]));
}

// ---- GET /api/state：JSON 狀態 ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out([
        'logical_now' => $logicalNow,
        'tasks' => $store->allTasks(),
        'runs' => $store->allRuns(),
        'notifications' => $store->allNotifications(),
    ]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($store, $logicalNow);
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
    }
    return [];
}

function wants_html(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    // JSON_INVALID_UTF8_SUBSTITUTE：對非法 UTF-8 位元組以 U+FFFD 取代，避免 json_encode 回傳 false
    return (string) json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

/** 狀態 → 顏色 class（用於徽章） */
function status_class(string $s): string
{
    return match ($s) {
        'DONE' => 'ok',
        'FAILED' => 'bad',
        'RUNNING', 'QUEUED' => 'run',
        'RETRY' => 'warn',
        default => 'idle',
    };
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function render_home(Store $store, int $logicalNow): void
{
    $tasks = $store->allTasks();
    $runs = $store->allRuns();
    $notifs = $store->allNotifications();

    // 任務列表
    $taskRows = '';
    foreach ($tasks as $t) {
        $nextRun = (int) ($t['next_run_at'] ?? 0);
        $nextStr = $nextRun > 0
            ? gmdate('H:i:s', $nextRun) . '（' . ($nextRun - $logicalNow) . 's 後）'
            : '—';
        $typeStr = $t['type'] === 'interval' ? "週期 / {$t['interval_sec']}s" : '一次性';
        $ff = !empty($t['force_fail']) ? " <span class='badge bad'>故意失敗</span>" : '';
        $taskRows .= '<tr>'
            . '<td><code>' . h($t['id']) . '</code></td>'
            . '<td>' . h($t['name']) . $ff . '</td>'
            . '<td>' . $typeStr . '</td>'
            . "<td><span class='badge " . status_class((string) $t['status']) . "'>" . h((string) $t['status']) . '</span></td>'
            . '<td>' . $nextStr . '</td>'
            . '</tr>';
    }
    $taskRows = $taskRows ?: '<tr><td colspan="5" class="muted">尚無任務，先在上方建立一個。</td></tr>';

    // 執行歷史（最新在上）
    $runRows = '';
    foreach (array_reverse($runs) as $r) {
        $result = $r['status'] === 'DONE' ? h((string) $r['result']) : ('<span class="muted">' . h((string) ($r['error'] ?? '—')) . '</span>');
        $runRows .= '<tr>'
            . '<td>#' . (int) $r['run_id'] . '</td>'
            . '<td><code>' . h((string) $r['task_id']) . '</code></td>'
            . '<td><code>' . h((string) $r['idempotency_key']) . '</code></td>'
            . '<td>' . (int) $r['attempt'] . '</td>'
            . "<td><span class='badge " . status_class((string) $r['status']) . "'>" . h((string) $r['status']) . '</span></td>'
            . '<td>' . $result . '</td>'
            . '</tr>';
    }
    $runRows = $runRows ?: '<tr><td colspan="6" class="muted">尚無執行紀錄。先 tick 入列，再 run worker。</td></tr>';

    // 通知收件匣
    $notifRows = '';
    foreach (array_reverse($notifs) as $n) {
        $notifRows .= '<li>📩 <strong>' . h((string) $n['message']) . '</strong> '
            . '<span class="muted">[' . h((string) $n['channel']) . ']</span> — '
            . h((string) $n['preview']) . '…</li>';
    }
    $notifRows = $notifRows ?: '<li class="muted">尚無通知。</li>';

    $nowStr = gmdate('Y-m-d H:i:s', $logicalNow) . ' UTC';

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>⑪ ChatGPT Tasks · Demo</title>
<style>
:root{--bg:#0f1419;--card:#1e2630;--soft:#1a2027;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--ok:#7ee787;--warn:#f0883e;--bad:#ff7b72}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.6}
.wrap{max-width:980px;margin:0 auto;padding:28px 20px 80px}
h1{font-size:1.6rem;margin:.2em 0}h2{font-size:1.15rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin:1.6em 0 .6em}
p{color:var(--dim)}
.panel{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin:1em 0}
label{display:block;font-size:.85rem;color:var(--dim);margin:.5em 0 .2em}
input,select{background:var(--soft);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 10px;font-size:.9rem}
input[type=text]{width:100%}
.row{display:flex;gap:14px;flex-wrap:wrap}.row>div{flex:1;min-width:160px}
button{background:var(--accent);border:none;color:#04101f;font-weight:700;border-radius:8px;padding:9px 16px;cursor:pointer;font-size:.9rem}
button.ghost{background:var(--soft);color:var(--text);border:1px solid var(--border)}
.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:1em 0}
table{width:100%;border-collapse:collapse;font-size:.86rem;margin:.5em 0}
th,td{border:1px solid var(--border);padding:7px 9px;text-align:left;vertical-align:top}
th{background:var(--soft);color:var(--accent)}
code{background:#161b22;padding:1px 5px;border-radius:4px;color:var(--ok);font-size:.85em}
.badge{display:inline-block;font-size:.72rem;padding:1px 8px;border-radius:999px;border:1px solid}
.badge.ok{color:var(--ok);border-color:var(--ok)}.badge.bad{color:var(--bad);border-color:var(--bad)}
.badge.run{color:var(--accent);border-color:var(--accent)}.badge.warn{color:var(--warn);border-color:var(--warn)}
.badge.idle{color:var(--dim);border-color:var(--border)}
.muted{color:var(--dim)}.clock{font-weight:700;color:var(--warn)}
ul{padding-left:1.2em}
.note{border-left:4px solid var(--warn);background:var(--soft);padding:10px 14px;border-radius:0 8px 8px 0;font-size:.85rem}
</style></head><body><div class="wrap">
<h1>⑪ ChatGPT Tasks · 排程 / 非同步 LLM 任務 Demo</h1>
<p>核心流程：<strong>建立任務 → tick（模擬時間前進，掃出到期任務入列）→ run worker（執行 + 狀態機 + 冪等/重試）→ 看結果與通知</strong>。</p>

<div class="panel">
  <strong>🕒 邏輯時鐘（模擬時間）：<span class="clock">$nowStr</span></strong>
  <div class="actions">
    <form method="post" action="/api/tick"><input type="hidden" name="advance" value="10"><button type="submit">⏩ tick：前進 10 秒並掃描到期</button></form>
    <form method="post" action="/api/run"><button type="submit">▶️ run worker（執行 QUEUED）</button></form>
    <form method="post" action="/api/reset"><button class="ghost" type="submit">🗑️ 重置</button></form>
  </div>
  <p class="muted" style="font-size:.82rem;margin:0">tick 會把 next_run_at ≤ 邏輯時間的任務入列（冪等去重）；週期任務入列後自動排定下次時間。</p>
</div>

<div class="panel">
  <strong>➕ 建立任務</strong>
  <form method="post" action="/api/tasks">
    <div class="row">
      <div><label>任務名稱</label><input type="text" name="name" placeholder="每天早上摘要科技新聞" required></div>
      <div><label>型態</label><select name="type"><option value="interval">週期（interval）</option><option value="once">一次性（once）</option></select></div>
    </div>
    <div class="row">
      <div><label>間隔秒數（週期用）</label><input type="text" name="interval_sec" value="10"></div>
      <div><label>延遲秒數（一次性用）</label><input type="text" name="delay_sec" value="5"></div>
      <div><label>LLM 提示 prompt</label><input type="text" name="prompt" value="摘要今天最重要的 3 則科技新聞"></div>
    </div>
    <div class="row">
      <div><label><input type="checkbox" name="force_fail" value="1" style="width:auto"> 模擬 LLM 失敗（示範重試 → FAILED）</label></div>
    </div>
    <div style="margin-top:10px"><button type="submit">建立任務</button></div>
  </form>
</div>

<h2>📋 任務清單（排程狀態 / 下次執行）</h2>
<table><tr><th>ID</th><th>名稱</th><th>型態</th><th>排程狀態</th><th>下次執行</th></tr>$taskRows</table>

<h2>🔁 執行歷史（狀態機 + 冪等鍵 + 嘗試次數）</h2>
<table><tr><th>run</th><th>任務</th><th>冪等鍵</th><th>嘗試</th><th>狀態</th><th>結果 / 錯誤</th></tr>$runRows</table>

<h2>📩 通知收件匣</h2>
<ul>$notifRows</ul>

<div class="note">⚠️ 誠實聲明：<strong>LLM 呼叫為假呼叫</strong>（依 prompt 產生確定性文字）；
<strong>排程器、延遲佇列、任務狀態機、冪等去重、失敗重試</strong>皆為真實可執行邏輯。</div>
</div></body></html>
HTML;
}
