<?php
declare(strict_types=1);

/**
 * 分散式限流器 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8019 -t public
 *
 * 路由：
 *   GET  /                      測試頁（設定規則、連打測試、看放行/拒絕）
 *   POST /api/config            設定限制 { algo, limit, window_sec }
 *   GET  /api/check?key=...     一次限流檢查 → 放行回 200，超限回 429 + Retry-After
 *   POST /api/reset             重置所有 key 狀態
 *   GET  /api/state             目前規則設定（供測試頁顯示）
 */

require __DIR__ . '/../src/RateLimiter.php';

$limiter = new RateLimiter(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/config：設定限制 ----
if ($method === 'POST' && $path === '/api/config') {
    $body = read_body();
    $algo   = (string) ($body['algo'] ?? 'token_bucket');
    $limit  = (int) ($body['limit'] ?? 5);
    $window = (int) ($body['window_sec'] ?? 10);
    $limiter->setConfig($algo, $limit, $window);
    exit(json_out(['ok' => true, 'config' => $limiter->getConfig()]));
}

// ---- GET /api/check：限流檢查（核心）----
if ($method === 'GET' && $path === '/api/check') {
    $key = trim((string) ($_GET['key'] ?? 'default'));
    if ($key === '') { $key = 'default'; }

    $r = $limiter->allow($key);

    // 標準限流回應標頭
    header('X-RateLimit-Limit: ' . $r['limit']);
    header('X-RateLimit-Remaining: ' . $r['remaining']);

    if ($r['allowed']) {
        // 放行 → 200
        exit(json_out([
            'allowed'   => true,
            'status'    => 200,
            'key'       => $key,
            'remaining' => $r['remaining'],
            'algo'      => $r['algo'],
        ]));
    }

    // 超限 → 429 + Retry-After
    http_response_code(429);
    header('Retry-After: ' . $r['retry_after']);
    exit(json_out([
        'allowed'     => false,
        'status'      => 429,
        'key'         => $key,
        'remaining'   => 0,
        'retry_after' => $r['retry_after'],
        'algo'        => $r['algo'],
        'message'     => 'Too Many Requests',
    ]));
}

// ---- POST /api/reset：重置 ----
if ($method === 'POST' && $path === '/api/reset') {
    $key = trim((string) (read_body()['key'] ?? ''));
    $limiter->reset($key !== '' ? $key : null);
    exit(json_out(['ok' => true]));
}

// ---- GET /api/state：目前規則 ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out(['config' => $limiter->getConfig()]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($limiter->getConfig());
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
function read_body(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
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

function render_home(array $cfg): void
{
    $algo   = htmlspecialchars((string) $cfg['algo']);
    $limit  = (int) $cfg['limit'];
    $window = (int) $cfg['window_sec'];
    $tbSel  = $algo === 'token_bucket' ? 'selected' : '';
    $swSel  = $algo === 'sliding_window' ? 'selected' : '';

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>分散式限流器 · Demo</title>
<style>
:root{--bg:#0f1419;--card:#1e2630;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--ok:#7ee787;--warn:#f0883e;--danger:#ff7b72;--code:#161b22}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.6;max-width:820px;margin:0 auto;padding:32px 20px 80px}
h1{font-size:1.6rem}h2{font-size:1.15rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin-top:1.8em}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin:1em 0}
label{display:inline-block;color:var(--dim);font-size:.9rem;margin-right:6px}
input,select{background:var(--code);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:6px 10px;font-size:.95rem}
button{background:var(--accent);color:#04121f;border:none;border-radius:6px;padding:8px 16px;font-weight:700;cursor:pointer;margin-right:8px}
button.ghost{background:var(--code);color:var(--text);border:1px solid var(--border)}
code{background:var(--code);color:var(--ok);padding:2px 6px;border-radius:6px;font-size:.88em}
.row{margin:.6em 0}
#log{font-family:ui-monospace,Consolas,monospace;font-size:.85rem;background:var(--code);border:1px solid var(--border);border-radius:8px;padding:12px;height:240px;overflow:auto;white-space:pre-wrap}
.pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:.8rem;font-weight:700}
.pill.ok{background:rgba(126,231,135,.15);color:var(--ok)}
.pill.no{background:rgba(255,123,114,.15);color:var(--danger)}
.note{color:var(--dim);font-size:.82rem}
.warnbox{border-left:4px solid var(--warn);background:#1a2027;padding:10px 14px;border-radius:0 8px 8px 0;margin:1em 0;font-size:.85rem;color:var(--dim)}
.warnbox b{color:var(--text)}
</style></head><body>
<h1>🚦 分散式限流器 · Demo</h1>
<p class="note">對每個 <code>key</code>（user/IP/API key）施加速率上限；超出回 <code>429 + Retry-After</code>。
狀態用檔案鎖原子更新，模擬 Redis 的 <code>INCR</code>/Lua 原子操作。</p>

<div class="card">
  <h2 style="margin-top:0">① 設定限制</h2>
  <div class="row">
    <label>演算法</label>
    <select id="algo">
      <option value="token_bucket" $tbSel>令牌桶 Token Bucket（容許突發）</option>
      <option value="sliding_window" $swSel>滑動視窗計數器（平滑邊界突刺）</option>
    </select>
  </div>
  <div class="row">
    <label>每</label><input id="window" type="number" value="$window" min="1" style="width:70px"> 秒
    <label style="margin-left:12px">允許</label><input id="limit" type="number" value="$limit" min="1" style="width:70px"> 次
  </div>
  <div class="row"><button onclick="saveConfig()">套用設定</button>
    <span class="note">目前：<code id="cfgnow">$algo · 每 {$window}s {$limit} 次</code></span></div>
</div>

<div class="card">
  <h2 style="margin-top:0">② 連打測試</h2>
  <div class="row">
    <label>key</label><input id="key" value="user-123" style="width:160px">
    <button onclick="hit()">打 1 次</button>
    <button onclick="burst(8)">連打 8 次</button>
    <button class="ghost" onclick="resetAll()">重置狀態</button>
  </div>
  <p class="note">先連打超過上限看被拒（429），等幾秒後再打看 token 補回 / 視窗滑動恢復放行。</p>
  <div id="log"></div>
</div>

<div class="warnbox">
  <b>誠實聲明：</b>本 demo 用檔案鎖（flock）做原子讀-改-寫，模擬真實系統的 Redis <code>INCR</code> / Lua 腳本原子性；單機示意、非真正分散式。
  令牌桶補充、滑動視窗加權、per-key 隔離、429 + Retry-After 等限流邏輯為真實可執行。
</div>

<script>
const logEl = document.getElementById('log');
function log(msg){ logEl.textContent = msg + "\\n" + logEl.textContent; }

async function saveConfig(){
  const body = { algo: algo.value, limit: +limit.value, window_sec: +window.value };
  const r = await fetch('/api/config', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
  const j = await r.json();
  document.getElementById('cfgnow').textContent = j.config.algo + ' · 每 ' + j.config.window_sec + 's ' + j.config.limit + ' 次';
  log('⚙️ 已套用設定並重置狀態：' + JSON.stringify(j.config));
}

async function hit(){
  const k = document.getElementById('key').value || 'default';
  const t = new Date().toLocaleTimeString();
  const r = await fetch('/api/check?key=' + encodeURIComponent(k));
  const j = await r.json();
  if (j.allowed){
    log(t + '  [200] ✅ 放行  key=' + j.key + '  剩餘=' + j.remaining + '  (' + j.algo + ')');
  } else {
    log(t + '  [429] ⛔ 拒絕  key=' + j.key + '  Retry-After=' + j.retry_after + 's  (' + j.algo + ')');
  }
}

async function burst(n){
  for (let i=0;i<n;i++){ await hit(); }
}

async function resetAll(){
  await fetch('/api/reset', {method:'POST', headers:{'Content-Type':'application/json'}, body:'{}'});
  log('🔄 已重置所有 key 狀態');
}
</script>
</body></html>
HTML;
}
