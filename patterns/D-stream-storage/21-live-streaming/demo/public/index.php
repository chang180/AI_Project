<?php
declare(strict_types=1);

/**
 * 直播平台 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8021 -t public
 *
 * 聚焦：低延遲 HLS 滑動視窗 manifest + 聊天 fan-out（非 CRUD）。
 *
 * 路由：
 *   GET  /                                測試頁
 *   POST /api/streams/start               開始直播 { title }
 *   POST /api/streams/segment             攝入推進：產生一個新 segment（推進 live edge）
 *   POST /api/streams/stop                結束直播
 *   GET  /api/streams/{id}/master.m3u8    主清單（各碼率）
 *   GET  /api/streams/{id}/720p/index.m3u8 直播 media manifest（滑動視窗）
 *   POST /api/streams/join                觀眾加入 { viewer }
 *   POST /api/streams/leave               觀眾離開 { viewer }
 *   POST /api/streams/chat                送聊天 { user, text } → fan-out
 *   GET  /api/streams/status              目前狀態（manifest 視窗 / 人數 / 聊天）JSON
 */

require __DIR__ . '/../src/IngestService.php';
require __DIR__ . '/../src/HlsPackager.php';
require __DIR__ . '/../src/ChatRoom.php';

const STREAM_ID = 'live_demo';

$dataDir  = __DIR__ . '/../data';
$ingest   = new IngestService($dataDir);
$packager = new HlsPackager($dataDir);
$chat     = new ChatRoom($dataDir);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/streams/start：開始直播 ----
if ($method === 'POST' && $path === '/api/streams/start') {
    $title = trim((string) input('title'));
    $ingest->start($title);
    $packager->reset();   // 新的一場直播：視窗清空、media sequence 歸零
    $chat->reset();       // 聊天室與在線名單清空
    redirect_or_json(['status' => 'LIVE', 'title' => $title]);
}

// ---- POST /api/streams/segment：攝入推進一段（推進 live edge）----
if ($method === 'POST' && $path === '/api/streams/segment') {
    if (!$ingest->isLive()) {
        http_response_code(409);
        exit(json_out(['error' => '直播尚未開始']));
    }
    $seq = $ingest->produceSegment();   // 取得新 segment 全域序號
    $packager->append($seq);            // 加入滑動視窗（舊段可能滾掉、media_sequence 遞增）
    redirect_or_json(['segment' => $seq, 'manifest' => $packager->status()]);
}

// ---- POST /api/streams/stop：結束直播 ----
if ($method === 'POST' && $path === '/api/streams/stop') {
    $ingest->stop();
    redirect_or_json(['status' => 'ENDED']);
}

// ---- GET master.m3u8 ----
if ($method === 'GET' && preg_match('#^/api/streams/([A-Za-z0-9_]+)/master\.m3u8$#', $path, $m)) {
    header('Content-Type: application/vnd.apple.mpegurl');
    echo $packager->renderMaster(base_url(), $m[1]);
    exit;
}

// ---- GET media manifest（滑動視窗，直播中無 ENDLIST）----
if ($method === 'GET' && preg_match('#^/api/streams/([A-Za-z0-9_]+)/[0-9]+p/index\.m3u8$#', $path)) {
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache'); // 直播 manifest 不可快取（內容隨時間滑動）
    echo $packager->renderManifest($ingest->isLive()); // LIVE→無 ENDLIST；ENDED→收尾
    exit;
}

// ---- POST /api/streams/join：觀眾加入 ----
if ($method === 'POST' && $path === '/api/streams/join') {
    $viewer = trim((string) input('viewer')) ?: ('viewer_' . substr(bin2hex(random_bytes(2)), 0, 4));
    $count = $chat->join($viewer);
    redirect_or_json(['viewer' => $viewer, 'viewers' => $count]);
}

// ---- POST /api/streams/leave：觀眾離開 ----
if ($method === 'POST' && $path === '/api/streams/leave') {
    $viewer = trim((string) input('viewer'));
    $count = $chat->leave($viewer);
    redirect_or_json(['viewer' => $viewer, 'viewers' => $count]);
}

// ---- POST /api/streams/chat：送聊天 → fan-out ----
if ($method === 'POST' && $path === '/api/streams/chat') {
    $user = trim((string) input('user')) ?: 'anon';
    $text = trim((string) input('text'));
    if ($text === '') {
        http_response_code(400);
        exit(json_out(['error' => 'text 不可為空']));
    }
    $fanout = $chat->send($user, $text);  // 扇出給房內每位觀眾
    redirect_or_json(['sent' => $text, 'fanout' => $fanout]);
}

// ---- GET /api/streams/status：JSON 狀態 ----
if ($method === 'GET' && $path === '/api/streams/status') {
    exit(json_out([
        'stream' => $ingest->state(),
        'manifest' => $packager->status(),
        'viewers' => $chat->viewerCount(),
        'chat_history' => $chat->history(),
    ]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($ingest, $packager, $chat, base_url());
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
function input(string $key): mixed
{
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j[$key])) {
            return $j[$key];
        }
    }
    return '';
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8021');
}

/** 表單送出（HTML）導回首頁；API 呼叫回 JSON */
function redirect_or_json(array $data): never
{
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        header('Location: /');
        exit;
    }
    exit(json_out($data));
}

function render_home(IngestService $ingest, HlsPackager $packager, ChatRoom $chat, string $base): void
{
    $state = $ingest->state();
    $status = $packager->status();
    $live = $state['status'] === 'LIVE';
    $statusBadge = $live ? '🔴 LIVE' : ($state['status'] === 'ENDED' ? '⚫ ENDED' : '⚪ IDLE');
    $title = htmlspecialchars((string) ($state['title'] ?? ''));

    // 視窗內 segment 清單
    $segList = $status['window'] ? implode(', ', array_map(fn($s) => "seg_$s.ts", $status['window'])) : '（空）';
    $manifest = htmlspecialchars($packager->renderManifest($live));

    // 聊天歷史
    $chatRows = '';
    foreach ($chat->history() as $msg) {
        $u = htmlspecialchars((string) $msg['user']);
        $t = htmlspecialchars((string) $msg['text']);
        $chatRows .= "<div>[{$msg['ts']}] <b>$u</b>: $t</div>";
    }
    if ($chatRows === '') {
        $chatRows = '<div style="color:#888">（尚無訊息）</div>';
    }

    // 在線觀眾
    $viewerList = $chat->viewers() ? implode(', ', array_map('htmlspecialchars', $chat->viewers())) : '（無）';
    $viewerCount = $chat->viewerCount();

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>直播平台 · Demo</title>
<style>
body{max-width:840px;margin:32px auto;padding:0 16px;background:#0f1419;color:#e6edf3;
  font-family:system-ui,"Microsoft JhengHei",sans-serif;line-height:1.6}
h1,h2{line-height:1.25}h2{border-bottom:1px solid #2a3540;padding-bottom:.3em;margin-top:1.6em}
.box{border-left:4px solid #58a6ff;background:#1a2027;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box.warn{border-color:#f0883e}.box.tip{border-color:#7ee787}.label{font-weight:700;display:block;margin-bottom:.2em}
code,pre{background:#161b22;border-radius:6px}code{padding:2px 6px;color:#7ee787}
pre{padding:12px 14px;border:1px solid #2a3540;overflow-x:auto;font-size:.85rem;color:#e6edf3}
button{background:#1f6feb;color:#fff;border:0;border-radius:6px;padding:8px 14px;cursor:pointer;font-size:.9rem}
button:hover{background:#388bfd}input{background:#0d1117;border:1px solid #2a3540;color:#e6edf3;border-radius:6px;padding:7px 10px}
form{display:inline-block;margin:4px 8px 4px 0}.badge{font-weight:700;font-size:1.1rem}
.muted{color:#9aa7b3;font-size:.85rem}.pill{display:inline-block;background:#161b22;border:1px solid #2a3540;border-radius:999px;padding:2px 12px;margin-right:6px}
</style></head><body>
<h1>📡 直播平台 · Demo <span class="badge">$statusBadge</span></h1>
<p class="muted">聚焦 <b>低延遲 HLS 滑動視窗 manifest</b> 與 <b>聊天 fan-out</b>。直播只留 live edge 附近數段，舊段直接滾掉。</p>

<h2>1. 直播控制（攝入）</h2>
<form method="post" action="/api/streams/start">
  <input name="title" placeholder="直播標題" value="$title" required>
  <button type="submit">▶ 開始直播</button>
</form>
<form method="post" action="/api/streams/segment">
  <button type="submit">＋ 產生 segment（推進 live edge）</button>
</form>
<form method="post" action="/api/streams/stop">
  <button type="submit" style="background:#6e1f2a">■ 結束直播</button>
</form>
<p class="muted">標題：<b>$title</b>　起始時間：{$state['started_at']}</p>

<h2>2. 滑動視窗 manifest（觀眾只看得到 live edge 附近）</h2>
<div class="box tip">
  <span class="label">視窗大小 N = {$status['window_size']}　EXT-X-MEDIA-SEQUENCE = {$status['media_sequence']}</span>
  目前視窗內 segment：<code>$segList</code><br>
  <span class="muted">每按一次「產生 segment」就加一段；超過 N 段時最舊一段滾掉、media sequence +1（舊段不再服務）。</span>
</div>
<pre>$manifest</pre>
<p class="muted">直播中刻意<b>不含 #EXT-X-ENDLIST</b> → 播放器會持續回來重抓；收播後才補 ENDLIST。
亦可直接看 <a style="color:#58a6ff" href="$base/api/streams/live_demo/720p/index.m3u8" target="_blank">720p/index.m3u8</a> ·
<a style="color:#58a6ff" href="$base/api/streams/live_demo/master.m3u8" target="_blank">master.m3u8</a></p>

<h2>3. 觀眾與聊天 fan-out（同時在線：$viewerCount）</h2>
<form method="post" action="/api/streams/join">
  <input name="viewer" placeholder="觀眾名稱（留空隨機）">
  <button type="submit">👤 加入</button>
</form>
<form method="post" action="/api/streams/leave">
  <input name="viewer" placeholder="要離開的觀眾">
  <button type="submit">👋 離開</button>
</form>
<p>在線觀眾：<span class="pill">$viewerList</span></p>
<form method="post" action="/api/streams/chat">
  <input name="user" placeholder="暱稱" value="alice">
  <input name="text" placeholder="聊天訊息" required style="width:40%">
  <button type="submit">💬 送出（fan-out 給房內所有人）</button>
</form>
<div class="box">
  <span class="label">聊天室（全房歷史）</span>
  $chatRows
</div>
<p class="muted">送一則訊息會 fan-out 複製進房內每位觀眾的收件匣 —— 放大倍數 = 訊息數 × 觀眾數，正是百萬人房間的成本來源。</p>

<div class="box warn"><span class="label">⚠️ 誠實聲明</span>
  無真 RTMP / ffmpeg / CDN / WebSocket；segment 內容為佔位文字。
  但<b>滑動視窗 manifest（只留最近 N 段、MEDIA-SEQUENCE 遞增、無 ENDLIST）與聊天 fan-out（分房廣播 + 同時在線計數）為真實可執行邏輯</b>。
</div>
</body></html>
HTML;
}
