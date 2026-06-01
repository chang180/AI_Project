<?php
declare(strict_types=1);

/**
 * E2EE Messaging Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8060 -t public
 *
 * 路由：
 *   GET  /                      測試頁（A / B 兩個裝置 + 伺服器密文視角）
 *   POST /api/session           建立 session：{ user, peer } → DH 算共享祕密 + 開棘輪
 *                               （若雙方尚未 register，會自動先 register）
 *   POST /api/send              加密發送：{ from, to, body } → 伺服器只拿到密文
 *   GET  /api/inbox?user=&peer= 收取並解密：裝置端用接收棘輪解出明文
 *   GET  /api/server            伺服器視角：公鑰目錄 + 密文信箱（證明只見密文）
 *   POST /api/forward-secrecy   示範前向保密：舊金鑰解不了（已被棘輪覆蓋）
 *   GET  /api/state             整體狀態（給測試頁刷新）
 */

require __DIR__ . '/../src/ToyCrypto.php';
require __DIR__ . '/../src/KeyServer.php';
require __DIR__ . '/../src/DeviceStore.php';
require __DIR__ . '/../src/Mailbox.php';
require __DIR__ . '/../src/E2EEService.php';

$dataDir = __DIR__ . '/../data';
$keys    = new KeyServer($dataDir);
$devices = new DeviceStore($dataDir);
$mailbox = new Mailbox($dataDir);
$svc     = new E2EEService($keys, $devices, $mailbox);

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($path, '/') ?: '/';

// ---- POST /api/session：雙方建立 session（DH） ----
if ($method === 'POST' && $path === '/api/session') {
    $b = body();
    $user = trim((string) ($b['user'] ?? ''));
    $peer = trim((string) ($b['peer'] ?? ''));
    if ($user === '' || $peer === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user / peer 皆必填']));
    }
    // 確保雙方都已註冊身分公鑰（裝置產生私鑰、上傳公鑰）
    $regUser = $svc->register($user);
    $regPeer = $svc->register($peer);
    // 雙向各自建立 session（兩邊各算共享祕密，應一致）
    $u = $svc->openSession($user, $peer);
    $p = $svc->openSession($peer, $user);
    exit(json_out([
        'registered' => [$regUser, $regPeer],
        'session'    => [$u, $p],
        'same_secret' => isset($u['shared_secret'], $p['shared_secret'])
                       && $u['shared_secret'] === $p['shared_secret'],
    ]));
}

// ---- POST /api/send：加密發送 ----
if ($method === 'POST' && $path === '/api/send') {
    $b = body();
    $from = trim((string) ($b['from'] ?? ''));
    $to   = trim((string) ($b['to'] ?? ''));
    $text = trim((string) ($b['body'] ?? ''));
    if ($from === '' || $to === '' || $text === '') {
        http_response_code(400);
        exit(json_out(['error' => 'from / to / body 皆必填']));
    }
    exit(json_out($svc->send($from, $to, $text)));
}

// ---- GET /api/inbox：收取並解密 ----
if ($method === 'GET' && $path === '/api/inbox') {
    $user = trim((string) ($_GET['user'] ?? ''));
    $peer = trim((string) ($_GET['peer'] ?? ''));
    if ($user === '' || $peer === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user / peer 皆必填']));
    }
    exit(json_out(['user' => $user, 'peer' => $peer, 'messages' => $svc->receive($user, $peer)]));
}

// ---- GET /api/server：伺服器視角 ----
if ($method === 'GET' && $path === '/api/server') {
    exit(json_out([
        'key_directory' => $keys->directory(),   // 只有公鑰
        'mailbox'       => $mailbox->all(),       // 只有密文
    ]));
}

// ---- POST /api/forward-secrecy：示範前向保密 ----
if ($method === 'POST' && $path === '/api/forward-secrecy') {
    $b = body();
    $user = trim((string) ($b['user'] ?? ''));
    $peer = trim((string) ($b['peer'] ?? ''));
    exit(json_out($svc->demoForwardSecrecy($user, $peer)));
}

// ---- GET /api/state：整體狀態 ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out([
        'key_directory' => $keys->directory(),
        'mailbox'       => $mailbox->all(),
        'session_AB'    => $devices->sessionInfo('A', 'B'),
        'session_BA'    => $devices->sessionInfo('B', 'A'),
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
<title>E2EE Messaging · Demo</title>
<style>
:root{color-scheme:dark}
body{max-width:1040px;margin:36px auto;padding:0 16px;background:#0f1419;color:#e6edf3;
  font-family:system-ui,"Microsoft JhengHei",sans-serif;line-height:1.6}
h1{font-size:1.6rem}h2{font-size:1.05rem;border-bottom:1px solid #2a3540;padding-bottom:.3em}
.cols{display:flex;gap:18px;flex-wrap:wrap}
.panel{flex:1;min-width:330px;background:#1e2630;border:1px solid #2a3540;border-radius:12px;padding:16px}
.panel.a{border-top:3px solid #58a6ff}.panel.b{border-top:3px solid #7ee787}
.panel.srv{border-top:3px solid #f0883e}
input,button{font:inherit;padding:7px 10px;border-radius:8px;border:1px solid #2a3540;background:#161b22;color:#e6edf3}
button{cursor:pointer;background:#21303f}button:hover{border-color:#58a6ff}
.row{display:flex;gap:6px;margin:6px 0;align-items:center;flex-wrap:wrap}
.log{background:#161b22;border:1px solid #2a3540;border-radius:8px;padding:10px;min-height:60px;
  font-size:.82rem;white-space:pre-wrap;word-break:break-all}
.msg{background:#161b22;border:1px solid #2a3540;border-radius:8px;padding:6px 10px;margin:5px 0;font-size:.85rem}
.n{color:#58a6ff;font-weight:700}.cipher{color:#f0883e}.plain{color:#7ee787}
.badge{display:inline-block;font-size:.75rem;padding:1px 8px;border-radius:999px;border:1px solid #2a3540}
.box{border-left:4px solid #e5484d;background:#1a2027;padding:10px 14px;border-radius:0 8px 8px 0;margin:14px 0;font-size:.85rem}
code{background:#161b22;padding:2px 6px;border-radius:6px;color:#7ee787}
</style></head><body>
<h1>🔐 E2EE Messaging · Demo</h1>
<p>玩具版 Diffie-Hellman 金鑰交換 + KDF 導金鑰 + 棘輪推進（前向保密）。A、B 兩個裝置端各自加解密；
中間的「伺服器」<strong>只看得到密文</strong>。</p>

<div class="row">
  <button onclick="openSession()">① A、B 建立加密 session（DH 算共享祕密）</button>
  <span id="sess"></span>
</div>

<div class="cols">
  <div class="panel a">
    <h2>📱 A 的裝置</h2>
    <div class="row">
      <input id="a_text" placeholder="A 要傳給 B 的明文" style="flex:1">
      <button onclick="send('A','B','a_text')">🔒 加密送給 B</button>
    </div>
    <div class="row"><button onclick="inbox('A','B')">📥 收 B 的訊息並解密</button></div>
    <h2>A 解出的明文</h2><div id="a_inbox" class="log"></div>
    <h2>A 的日誌</h2><div id="a_log" class="log"></div>
  </div>

  <div class="panel b">
    <h2>📱 B 的裝置</h2>
    <div class="row">
      <input id="b_text" placeholder="B 要傳給 A 的明文" style="flex:1">
      <button onclick="send('B','A','b_text')">🔒 加密送給 A</button>
    </div>
    <div class="row"><button onclick="inbox('B','A')">📥 收 A 的訊息並解密</button></div>
    <h2>B 解出的明文</h2><div id="b_inbox" class="log"></div>
    <h2>B 的日誌</h2><div id="b_log" class="log"></div>
  </div>
</div>

<div class="panel srv" style="margin-top:18px">
  <h2>🖥️ 伺服器視角（只見密文！）<button onclick="refresh()" style="float:right">🔄 刷新</button></h2>
  <p style="font-size:.85rem">金鑰目錄只有<strong>公鑰</strong>；信箱只有<strong>密文</strong>。伺服器沒有任何私鑰或明文，因此解不開訊息。</p>
  <h2>公鑰目錄</h2><div id="srv_keys" class="log"></div>
  <h2>密文信箱（伺服器所見的全部）</h2><div id="srv_box" class="log"></div>
  <h2>棘輪狀態（裝置端，每收發一則就推進）</h2><div id="ratchet" class="log"></div>
</div>

<div class="row" style="margin-top:14px">
  <button onclick="fs('A','B')">② 示範前向保密：A 用舊金鑰解舊訊息（解不開）</button>
  <button onclick="fs('B','A')">B 同上</button>
</div>
<div id="fs_out" class="log"></div>

<div class="box"><strong>⚠️ 嚴正誠實聲明（請務必看）：</strong>
本 demo 用<strong>小數字 Diffie-Hellman（p=467）+ XOR 對稱加密</strong>，純為「看得懂」的教學示意，
<strong>絕對不是安全加密</strong>——小質數可被秒破、XOR 無完整性驗證可被竄改。
但底層<strong>概念是真的、與 Signal Protocol 一致</strong>：DH 雙方算出相同共享祕密、KDF 導金鑰、
棘輪每則單向推進（舊金鑰算不出新的）達成前向保密、伺服器只中繼密文。
真實系統用 <code>X3DH</code> 初始協商 + <code>Double Ratchet</code> + <code>AES-GCM</code> / <code>Curve25519</code>。</div>

<script>
async function api(m,u,d){const o={method:m,headers:{'Content-Type':'application/json'}};
  if(d)o.body=JSON.stringify(d);const r=await fetch(u,o);return r.json();}
function log(who,t){const el=document.getElementById(who.toLowerCase()+'_log');
  el.textContent=(new Date().toLocaleTimeString())+'  '+t+'\n'+el.textContent;}
function esc(s){return String(s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}

async function openSession(){
  const r=await api('POST','/api/session',{user:'A',peer:'B'});
  const u=r.session[0], p=r.session[1];
  document.getElementById('sess').innerHTML =
    '<span class="badge">A 算出共享祕密 '+u.shared_secret+'</span> '+
    '<span class="badge">B 算出 '+p.shared_secret+'</span> '+
    '<span class="badge '+(r.same_secret?'':'')+'">'+(r.same_secret?'✅ 兩邊一致':'❌ 不一致')+'</span>';
  log('A','建立 session：共享祕密='+u.shared_secret+'，根金鑰指紋='+u.root_key_tip);
  log('B','建立 session：共享祕密='+p.shared_secret+'，根金鑰指紋='+p.root_key_tip);
  refresh();
}
async function send(f,t,inp){const v=document.getElementById(inp).value.trim();if(!v)return;
  const r=await api('POST','/api/send',{from:f,to:t,body:v});
  if(r.error){log(f,'⚠️ '+r.error);return;}
  document.getElementById(inp).value='';
  log(f,'🔒 加密送出 #n'+r.n+'：明文「'+r.plaintext+'」→ 密文 '+r.server_sees.cipher.slice(0,24)+'…（金鑰指紋 '+r.message_key_tip+'）');
  refresh();}
async function inbox(u,peer){const r=await api('GET','/api/inbox?user='+u+'&peer='+peer);
  const box=document.getElementById(u.toLowerCase()+'_inbox');
  if(!r.messages.length){log(u,'📥 無新訊息');return;}
  r.messages.forEach(m=>{box.innerHTML='<div class="msg"><span class="n">#n'+m.n+'</span> '+
    '<span class="cipher">密文 '+esc(m.cipher.slice(0,20))+'…</span> → <span class="plain">解出「'+esc(m.decrypted)+'」</span></div>'+box.innerHTML;});
  log(u,'📥 收到並解密 '+r.messages.length+' 則');refresh();}
async function fs(u,peer){const r=await api('POST','/api/forward-secrecy',{user:u,peer:peer});
  document.getElementById('fs_out').textContent =
    (r.explain||r.note||r.error)+(r.recover_attempt!==undefined?('\n舊訊息 #n'+r.old_message_n+' 重解結果：'+esc(r.recover_attempt)):'');}
async function refresh(){
  const s=await api('GET','/api/state');
  document.getElementById('srv_keys').textContent = Object.entries(s.key_directory).map(([u,k])=>
    u+': 公鑰='+k.public_key+'  指紋='+k.fingerprint).join('\n')||'（無）';
  document.getElementById('srv_box').textContent = s.mailbox.length
    ? s.mailbox.map(m=>'#'+m.id+'  '+m.from+'→'+m.to+'  n='+m.n+'  密文='+m.cipher).join('\n')
    : '（無）';
  const r=[];
  if(s.session_AB)r.push('A→B 發送棘輪 send_n='+s.session_AB.send_n+'  接收棘輪 recv_n='+s.session_AB.recv_n+'  (鏈 '+s.session_AB.send_chain_tip+' / '+s.session_AB.recv_chain_tip+')');
  if(s.session_BA)r.push('B→A 發送棘輪 send_n='+s.session_BA.send_n+'  接收棘輪 recv_n='+s.session_BA.recv_n+'  (鏈 '+s.session_BA.send_chain_tip+' / '+s.session_BA.recv_chain_tip+')');
  document.getElementById('ratchet').textContent = r.join('\n')||'（尚未建立 session）';
}
refresh();
</script>
</body></html>
HTML;
}
