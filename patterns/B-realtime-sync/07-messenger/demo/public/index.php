<?php
declare(strict_types=1);

/**
 * Messenger Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8007 -t public
 *
 * 路由：
 *   GET  /                       測試頁（兩個對話框 A / B + presence + 推播紀錄）
 *   POST /api/connect            上線 { user, gateway } → 註冊 + 補收離線訊息
 *   POST /api/disconnect         下線 { user }
 *   POST /api/heartbeat          心跳 { user }（維持 presence）
 *   POST /api/send               送訊息 { from, to, body } → 在線投遞 / 離線佇列
 *   GET  /api/poll?user=         poll 收件匣（模擬 WS 被動收訊）
 *   POST /api/read               已讀回條 { reader, peer }
 *   GET  /api/history?a=&b=      取對話歷史（含序號 / 已讀）
 *   GET  /api/state              整體狀態（presence + 推播紀錄），給測試頁刷新
 */

require __DIR__ . '/../src/ConnectionRegistry.php';
require __DIR__ . '/../src/MessageStore.php';
require __DIR__ . '/../src/MessageRouter.php';

$dataDir  = __DIR__ . '/../data';
$registry = new ConnectionRegistry($dataDir);
$store    = new MessageStore($dataDir);
$router   = new MessageRouter($registry, $store);

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($path, '/') ?: '/';

// ---- POST /api/connect：上線 + 補收離線 ----
if ($method === 'POST' && $path === '/api/connect') {
    $b = body();
    $user    = trim((string) ($b['user'] ?? ''));
    $gateway = trim((string) ($b['gateway'] ?? 'gw-1'));
    if ($user === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user 必填']));
    }
    $flushed = $router->onConnect($user, $gateway);
    exit(json_out(['ok' => true, 'user' => $user, 'gateway' => $gateway, 'flushed_offline' => $flushed]));
}

// ---- POST /api/disconnect：下線 ----
if ($method === 'POST' && $path === '/api/disconnect') {
    $user = trim((string) (body()['user'] ?? ''));
    if ($user === '') { http_response_code(400); exit(json_out(['error' => 'user 必填'])); }
    $registry->disconnect($user);
    exit(json_out(['ok' => true, 'user' => $user, 'online' => false]));
}

// ---- POST /api/heartbeat：心跳 ----
if ($method === 'POST' && $path === '/api/heartbeat') {
    $user = trim((string) (body()['user'] ?? ''));
    $ok = $registry->heartbeat($user);
    exit(json_out(['ok' => $ok, 'user' => $user]));
}

// ---- POST /api/send：送訊息 ----
if ($method === 'POST' && $path === '/api/send') {
    $b = body();
    $from = trim((string) ($b['from'] ?? ''));
    $to   = trim((string) ($b['to'] ?? ''));
    $text = trim((string) ($b['body'] ?? ''));
    if ($from === '' || $to === '' || $text === '') {
        http_response_code(400);
        exit(json_out(['error' => 'from / to / body 皆必填']));
    }
    exit(json_out($router->send($from, $to, $text)));
}

// ---- GET /api/poll：poll 收件匣 ----
if ($method === 'GET' && $path === '/api/poll') {
    $user = trim((string) ($_GET['user'] ?? ''));
    exit(json_out(['user' => $user, 'messages' => $router->receive($user)]));
}

// ---- POST /api/read：已讀回條 ----
if ($method === 'POST' && $path === '/api/read') {
    $b = body();
    $reader = trim((string) ($b['reader'] ?? ''));
    $peer   = trim((string) ($b['peer'] ?? ''));
    exit(json_out(['ok' => true, 'marked' => $router->markRead($reader, $peer)]));
}

// ---- GET /api/history：對話歷史 ----
if ($method === 'GET' && $path === '/api/history') {
    $a = trim((string) ($_GET['a'] ?? ''));
    $b = trim((string) ($_GET['b'] ?? ''));
    exit(json_out(['messages' => $router->history($a, $b)]));
}

// ---- GET /api/state：整體狀態 ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out([
        'presence' => $registry->all(),
        'push_log' => $store->pushLog(),
    ]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home();
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
function body(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = (string) file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
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
<title>Messenger · Demo</title>
<style>
:root{color-scheme:dark}
body{max-width:1000px;margin:36px auto;padding:0 16px;background:#0f1419;color:#e6edf3;
  font-family:system-ui,"Microsoft JhengHei",sans-serif;line-height:1.6}
h1{font-size:1.6rem}h2{font-size:1.1rem;border-bottom:1px solid #2a3540;padding-bottom:.3em}
.cols{display:flex;gap:18px;flex-wrap:wrap}
.panel{flex:1;min-width:320px;background:#1e2630;border:1px solid #2a3540;border-radius:12px;padding:16px}
.panel.a{border-top:3px solid #58a6ff}.panel.b{border-top:3px solid #7ee787}
input,button{font:inherit;padding:7px 10px;border-radius:8px;border:1px solid #2a3540;background:#161b22;color:#e6edf3}
button{cursor:pointer;background:#21303f}button:hover{border-color:#58a6ff}
.row{display:flex;gap:6px;margin:6px 0;align-items:center;flex-wrap:wrap}
.log{background:#161b22;border:1px solid #2a3540;border-radius:8px;padding:10px;min-height:80px;
  font-size:.85rem;white-space:pre-wrap;word-break:break-all}
.msg{background:#161b22;border:1px solid #2a3540;border-radius:8px;padding:6px 10px;margin:5px 0;font-size:.9rem}
.msg .seq{color:#58a6ff;font-weight:700}.msg .read{color:#7ee787}.msg .unread{color:#9aa7b3}
.badge{display:inline-block;font-size:.75rem;padding:1px 8px;border-radius:999px;border:1px solid #2a3540}
.on{color:#7ee787;border-color:#7ee787}.off{color:#9aa7b3}
.box{border-left:4px solid #f0883e;background:#1a2027;padding:10px 14px;border-radius:0 8px 8px 0;margin:14px 0;font-size:.85rem}
code{background:#161b22;padding:2px 6px;border-radius:6px;color:#7ee787}
table{width:100%;border-collapse:collapse;font-size:.85rem}td,th{border:1px solid #2a3540;padding:5px 8px;text-align:left}
</style></head><body>
<h1>💬 Messenger · Demo</h1>
<p>連線註冊表 + 路由投遞 + 順序 / 已讀 / 離線。兩個面板模擬使用者 <code>A</code>、<code>B</code> 各自的 client。</p>

<div class="row">
  <strong>presence：</strong><span id="presence"></span>
  <button onclick="refresh()">🔄 刷新狀態</button>
</div>

<div class="cols">
  <div class="panel a">
    <h2>👤 A 的 client</h2>
    <div class="row">
      <button onclick="conn('A')">上線(gw-1)</button>
      <button onclick="disc('A')">下線</button>
      <button onclick="poll('A')">poll 收件匣</button>
      <button onclick="read('A','B')">標記已讀(來自B)</button>
    </div>
    <div class="row">
      <input id="a_text" placeholder="傳給 B 的訊息" style="flex:1">
      <button onclick="send('A','B','a_text')">送給 B</button>
    </div>
    <h2>收到的訊息</h2><div id="a_inbox" class="log"></div>
    <h2>操作日誌</h2><div id="a_log" class="log"></div>
  </div>

  <div class="panel b">
    <h2>👤 B 的 client</h2>
    <div class="row">
      <button onclick="conn('B')">上線(gw-2)</button>
      <button onclick="disc('B')">下線</button>
      <button onclick="poll('B')">poll 收件匣</button>
      <button onclick="read('B','A')">標記已讀(來自A)</button>
    </div>
    <div class="row">
      <input id="b_text" placeholder="傳給 A 的訊息" style="flex:1">
      <button onclick="send('B','A','b_text')">送給 A</button>
    </div>
    <h2>收到的訊息</h2><div id="b_inbox" class="log"></div>
    <h2>操作日誌</h2><div id="b_log" class="log"></div>
  </div>
</div>

<h2>📨 對話歷史 A ↔ B（含序號與已讀回條）</h2>
<div id="history" class="log"></div>

<h2>🔔 推播紀錄（APNs / FCM，收件人離線時觸發）</h2>
<div id="push" class="log"></div>

<div class="box"><strong>⚠️ 示意 vs 真實：</strong>真實 Messenger 用 <code>WebSocket</code> 維持長連線、由收件人那台 gateway 主動 push；
這裡用 <code>HTTP poll</code> 取代「server push」，但<strong>連線註冊表（user→gateway）、路由投遞決策、per-conversation 序號、已讀回條、離線佇列補收、presence/推播</strong>等系統邏輯皆為真實可執行。</div>

<script>
const gw = {A:'gw-1', B:'gw-2'};
async function api(m,u,d){const o={method:m,headers:{'Content-Type':'application/json'}};
  if(d)o.body=JSON.stringify(d);const r=await fetch(u,o);return r.json();}
function log(who,t){const el=document.getElementById(who.toLowerCase()+'_log');
  el.textContent=(new Date().toLocaleTimeString())+'  '+t+'\n'+el.textContent;}

async function conn(u){const r=await api('POST','/api/connect',{user:u,gateway:gw[u]});
  log(u,'上線 @'+gw[u]+'（補收離線 '+r.flushed_offline+' 則）');refresh();}
async function disc(u){await api('POST','/api/disconnect',{user:u});log(u,'下線');refresh();}
async function send(f,t,inp){const v=document.getElementById(inp).value.trim();if(!v)return;
  const r=await api('POST','/api/send',{from:f,to:t,body:v});document.getElementById(inp).value='';
  log(f,'送出 #seq'+r.seq+' → '+r.delivery.mode+(r.delivery.via_gateway?'('+r.delivery.via_gateway+')':'(推播)'));
  refresh();}
async function poll(u){const r=await api('GET','/api/poll?user='+u);
  const box=document.getElementById(u.toLowerCase()+'_inbox');
  if(!r.messages.length){log(u,'poll：無新訊息');return;}
  r.messages.forEach(m=>{box.innerHTML+='<div class="msg"><span class="seq">#seq'+m.seq+'</span> '+
    '['+m.from+'→'+m.to+'] '+escapeHtml(m.body)+'</div>';});
  log(u,'poll：收到 '+r.messages.length+' 則');refresh();}
async function read(reader,peer){const r=await api('POST','/api/read',{reader:reader,peer:peer});
  log(reader,'標記已讀 '+r.marked.length+' 則');refresh();}
async function refresh(){
  const s=await api('GET','/api/state');
  document.getElementById('presence').innerHTML=Object.entries(s.presence).map(([u,p])=>
    '<span class="badge '+(p.online?'on':'off')+'">'+u+' '+(p.online?'在線@'+p.gateway:'離線')+'</span> ').join('')||'（尚無使用者）';
  document.getElementById('push').textContent=s.push_log.length
    ? s.push_log.map(p=>'→ '+p.to+'  msg#'+p.message+'  via '+p.channel).join('\n') : '（無）';
  const h=await api('GET','/api/history?a=A&b=B');
  document.getElementById('history').innerHTML=h.messages.length
    ? h.messages.map(m=>'<div class="msg"><span class="seq">#seq'+m.seq+'</span> ['+m.from+'→'+m.to+'] '+
      escapeHtml(m.body)+' <span class="'+(m.read?'read':'unread')+'">'+(m.read?'✓✓ 已讀':'• 未讀')+'</span></div>').join('')
    : '（無）';
}
function escapeHtml(s){return s.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
refresh();
</script>
</body></html>
HTML;
}
