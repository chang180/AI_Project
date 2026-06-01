<?php
declare(strict_types=1);

/**
 * API Gateway / Load Balancer Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8049 -t public
 *
 * 路由：
 *   GET  /                  測試頁（註冊服務與後端、送請求看挑到哪台、kill 一台看繞過、連續失敗觸發熔斷）
 *   POST /api/upstreams     註冊服務池 { prefix, backends:[...] }
 *   POST /api/algo          切換 LB 演算法 { algo }
 *   POST /api/request       模擬一次轉發 { path, fail?, hashKey? } → 回挑中的後端
 *   POST /api/kill          標記後端 down / up { prefix, backend, up? }
 *   POST /api/reset         全部重置
 *   GET  /api/state         目前完整狀態
 */

require __DIR__ . '/../src/Gateway.php';

$gw = new Gateway(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

if ($method === 'POST' && $path === '/api/upstreams') {
    $b = read_body();
    $prefix = (string) ($b['prefix'] ?? '');
    $backends = is_array($b['backends'] ?? null) ? $b['backends'] : [];
    exit(json_out($gw->registerUpstream($prefix, $backends)));
}

if ($method === 'POST' && $path === '/api/algo') {
    $b = read_body();
    exit(json_out($gw->setAlgo((string) ($b['algo'] ?? 'round_robin'))));
}

if ($method === 'POST' && $path === '/api/request') {
    $b = read_body();
    $r = $gw->route(
        (string) ($b['path'] ?? '/'),
        (bool) ($b['fail'] ?? false),
        (string) ($b['hashKey'] ?? '')
    );
    http_response_code((int) ($r['status'] ?? 200));
    exit(json_out($r));
}

if ($method === 'POST' && $path === '/api/kill') {
    $b = read_body();
    exit(json_out($gw->kill(
        (string) ($b['prefix'] ?? ''),
        (string) ($b['backend'] ?? ''),
        (bool) ($b['up'] ?? false)
    )));
}

if ($method === 'POST' && $path === '/api/reset') {
    exit(json_out($gw->reset()));
}

if ($method === 'GET' && $path === '/api/state') {
    exit(json_out($gw->state()));
}

if ($method === 'GET' && $path === '/') {
    render_home();
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

function render_home(): void
{
    echo <<<'HTML'
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>API Gateway / Load Balancer · Demo</title>
<style>
:root{--bg:#0f1419;--card:#1e2630;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--ok:#7ee787;--warn:#f0883e;--danger:#ff7b72;--code:#161b22}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.6;max-width:900px;margin:0 auto;padding:32px 20px 80px}
h1{font-size:1.6rem}h2{font-size:1.12rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin-top:0}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin:1em 0}
label{display:inline-block;color:var(--dim);font-size:.9rem;margin-right:6px}
input,select{background:var(--code);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:6px 10px;font-size:.95rem}
button{background:var(--accent);color:#04121f;border:none;border-radius:6px;padding:8px 14px;font-weight:700;cursor:pointer;margin:2px 4px 2px 0}
button.ghost{background:var(--code);color:var(--text);border:1px solid var(--border)}
button.danger{background:var(--danger);color:#1a0000}
code{background:var(--code);color:var(--ok);padding:2px 6px;border-radius:6px;font-size:.88em}
.row{margin:.6em 0}
#log{font-family:ui-monospace,Consolas,monospace;font-size:.84rem;background:var(--code);border:1px solid var(--border);border-radius:8px;padding:12px;height:220px;overflow:auto;white-space:pre-wrap}
table{width:100%;border-collapse:collapse;margin-top:8px;font-size:.88rem}
th,td{border:1px solid var(--border);padding:6px 8px;text-align:left}
th{color:var(--dim);font-weight:600}
.pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:.78rem;font-weight:700}
.pill.up{background:rgba(126,231,135,.15);color:var(--ok)}
.pill.down{background:rgba(255,123,114,.15);color:var(--danger)}
.pill.closed{background:rgba(126,231,135,.12);color:var(--ok)}
.pill.open{background:rgba(255,123,114,.15);color:var(--danger)}
.pill.half_open{background:rgba(240,136,62,.15);color:var(--warn)}
.note{color:var(--dim);font-size:.82rem}
.bar{display:inline-block;height:10px;background:var(--accent);border-radius:3px;vertical-align:middle}
.warnbox{border-left:4px solid var(--warn);background:#1a2027;padding:10px 14px;border-radius:0 8px 8px 0;margin:1em 0;font-size:.85rem;color:var(--dim)}
.warnbox b{color:var(--text)}
</style></head><body>
<h1>🚪 API Gateway / Load Balancer · Demo</h1>
<p class="note">統一入口：<b>路由</b>（依路徑前綴選服務池）→ <b>負載均衡</b>（RR / 最少連線 / 一致性雜湊）→ <b>健康檢查</b>（繞過 down 的後端）→ <b>熔斷</b>（連續失敗 3 次打開，冷卻 10s 後半開試探）。狀態用檔案鎖原子更新，模擬真實 Gateway 對共享狀態的原子操作。</p>

<div class="card">
  <h2>① 註冊服務池（路徑前綴 → 一組後端）</h2>
  <div class="row">
    <label>前綴</label><input id="prefix" value="/users" style="width:120px">
    <label style="margin-left:10px">後端(逗號分隔)</label><input id="backends" value="users-1,users-2,users-3" style="width:280px">
    <button onclick="reg()">註冊</button>
  </div>
  <div class="row">
    <label>LB 演算法</label>
    <select id="algo" onchange="setAlgo()">
      <option value="round_robin">round-robin（輪詢）</option>
      <option value="least_conn">least-connection（最少連線）</option>
      <option value="consistent_hash">consistent-hash（一致性雜湊）</option>
    </select>
    <button class="ghost" onclick="seedDemo()">一鍵建範例(/users + /orders)</button>
    <button class="danger" onclick="resetAll()">重置全部</button>
  </div>
</div>

<div class="card">
  <h2>② 送請求 → 看挑到哪一台</h2>
  <div class="row">
    <label>路徑</label><input id="reqpath" value="/users/42" style="width:160px">
    <label style="margin-left:8px">hashKey(可選)</label><input id="hashkey" value="" placeholder="一致性雜湊用" style="width:120px">
    <button onclick="send(false)">送 1 次</button>
    <button onclick="burst(12)">連送 12 次</button>
    <button class="ghost" onclick="send(true)">送 1 次(模擬失敗)</button>
    <button class="ghost" onclick="failBurst()">對同一台連續失敗 ×4(觸發熔斷)</button>
  </div>
  <p class="note">連送看分布（RR 平均輪流 / 最少連線挑閒的 / 一致性雜湊同 key 固定一台）；對某台連續失敗 3 次 → 熔斷打開自動繞過；等 10 秒看它變半開試探。</p>
  <div id="log"></div>
</div>

<div class="card">
  <h2>③ 後端狀態（健康 / 連線 / 被導流次數 / 熔斷器）</h2>
  <div id="tables"></div>
</div>

<div class="warnbox">
  <b>誠實聲明：</b>本 demo 為<b>單機模擬</b>——後端不是真的服務，而是記在 JSON 裡的計數器（用 <code>flock</code> 原子更新模擬真實 Gateway 對共享狀態的原子操作）。但<b>路由（最長前綴）、三種 LB 演算法、健康檢查繞過、熔斷器 closed→open→half_open→closed 的狀態轉移皆為真實可執行的邏輯</b>。要上線只需把「JSON 計數器」換成真正轉發 HTTP + 主動健康探測，決策邏輯不變。
</div>

<script>
const logEl = document.getElementById('log');
function log(m){ logEl.textContent = m + "\n" + logEl.textContent; }
async function api(p, body){
  const r = await fetch(p, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body||{})});
  return r.json();
}
async function reg(){
  const prefix = document.getElementById('prefix').value;
  const backends = document.getElementById('backends').value.split(',').map(s=>s.trim()).filter(Boolean);
  const j = await api('/api/upstreams', {prefix, backends});
  log('🧩 註冊 ' + JSON.stringify(j)); refresh();
}
async function setAlgo(){
  const algo = document.getElementById('algo').value;
  const j = await api('/api/algo', {algo});
  log('⚙️ 演算法 → ' + j.algo); refresh();
}
async function seedDemo(){
  await api('/api/reset', {});
  await api('/api/upstreams', {prefix:'/users', backends:['users-1','users-2','users-3']});
  await api('/api/upstreams', {prefix:'/orders', backends:['orders-1','orders-2']});
  log('🌱 已建範例：/users(3 台) + /orders(2 台)'); refresh();
}
async function send(fail){
  const path = document.getElementById('reqpath').value;
  const hashKey = document.getElementById('hashkey').value;
  const j = await api('/api/request', {path, fail, hashKey});
  if (j.ok){
    log('[' + j.status + '] ✅ ' + path + ' → ' + j.chosen + '  (' + j.algo + (j.probe?' · 半開試探成功→關閉熔斷':'') + ')');
  } else if (j.status === 502){
    log('[502] ⚠️ ' + path + ' → ' + j.chosen + ' 處理失敗 (連續失敗=' + j.fails + ' · 熔斷=' + j.cb_after + ')');
  } else {
    log('[' + j.status + '] ⛔ ' + (j.reason || JSON.stringify(j)));
  }
  refresh();
}
async function burst(n){ for(let i=0;i<n;i++){ await send(false); } }
async function failBurst(){
  // 對「下一台被挑中的」連續失敗 4 次：用一致性雜湊鎖同一台最直觀，但這裡直接連送 fail
  for(let i=0;i<4;i++){ await send(true); }
}
async function resetAll(){ await api('/api/reset', {}); log('🔄 已重置'); refresh(); }
async function killBackend(prefix, backend, up){
  const j = await api('/api/kill', {prefix, backend, up});
  log((up?'💚 拉起 ':'💀 kill ') + backend + ' ' + JSON.stringify(j)); refresh();
}
async function refresh(){
  const s = await (await fetch('/api/state')).json();
  document.getElementById('algo').value = s.algo;
  const el = document.getElementById('tables');
  let html = '';
  const prefixes = Object.keys(s.upstreams || {});
  if (prefixes.length === 0){ el.innerHTML = '<p class="note">尚未註冊任何服務池。</p>'; return; }
  const maxServed = Math.max(1, ...prefixes.flatMap(p => s.upstreams[p].backends.map(b=>b.served)));
  for (const p of prefixes){
    const pool = s.upstreams[p];
    html += '<h3 style="margin:.8em 0 .2em;font-size:1rem">'+p+' <span class="note">('+pool.backends.length+' 台)</span></h3>';
    html += '<table><tr><th>後端</th><th>健康</th><th>熔斷器</th><th>連線</th><th>被導流(分布)</th><th>連續失敗</th><th>操作</th></tr>';
    for (const b of pool.backends){
      const w = Math.round(b.served / maxServed * 120);
      html += '<tr>'
        + '<td><code>'+b.id+'</code></td>'
        + '<td><span class="pill '+(b.up?'up':'down')+'">'+(b.up?'UP':'DOWN')+'</span></td>'
        + '<td><span class="pill '+b.cb+'">'+b.cb+'</span></td>'
        + '<td>'+b.conns+'</td>'
        + '<td>'+b.served+' <span class="bar" style="width:'+w+'px"></span></td>'
        + '<td>'+b.fails+'</td>'
        + '<td>'+(b.up
            ? '<button class="danger" onclick="killBackend(\''+p+'\',\''+b.id+'\',false)">kill</button>'
            : '<button class="ghost" onclick="killBackend(\''+p+'\',\''+b.id+'\',true)">拉起</button>')+'</td>'
        + '</tr>';
    }
    html += '</table>';
  }
  el.innerHTML = html;
}
refresh();
</script>
</body></html>
HTML;
}
