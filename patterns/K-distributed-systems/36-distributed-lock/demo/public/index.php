<?php
declare(strict_types=1);

/**
 * 分散式鎖 + 服務發現 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8036 -t public
 *
 * 路由：
 *   GET  /                測試頁（acquire/release/renew 鎖 + fencing token 示範、watch、
 *                                 leader election、register/discover 服務、config put/get/watch）
 *   POST /api/lock        acquire { key, owner, ttl }            → 回 fencing token
 *   POST /api/unlock      release { key, owner }
 *   POST /api/renew       renew/heartbeat { key, owner, ttl }
 *   POST /api/fence-write 帶 fencing token 寫下游資源 { resource, token, payload }（示範舊 token 被拒）
 *   POST /api/campaign    leader election { election, candidate, ttl }
 *   GET  /api/leader      查目前 leader ?election=
 *   POST /api/watch       註冊 watcher { key } → watcherId
 *   GET  /api/watch       poll 事件 ?key=&watcherId=
 *   POST /api/config      put 設定 { key, value }
 *   GET  /api/config      get 設定 ?key=
 *   POST /api/register    服務註冊/心跳 { service, instance, addr, ttl }
 *   GET  /api/discover    服務發現 ?service=
 *   GET  /api/snapshot    狀態快照
 */

require __DIR__ . '/../src/CoordService.php';

$coord = new CoordService(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- POST /api/lock：acquire ----
if ($method === 'POST' && $path === '/api/lock') {
    $in = read_body();
    $key = trim((string) ($in['key'] ?? 'resource-x'));
    $owner = trim((string) ($in['owner'] ?? 'clientA'));
    $ttl = (float) ($in['ttl'] ?? 10);
    exit(json_out($coord->acquire($key, $owner, $ttl)));
}

// ---- POST /api/unlock：release ----
if ($method === 'POST' && $path === '/api/unlock') {
    $in = read_body();
    exit(json_out($coord->release(
        trim((string) ($in['key'] ?? '')),
        trim((string) ($in['owner'] ?? ''))
    )));
}

// ---- POST /api/renew：續租 / heartbeat ----
if ($method === 'POST' && $path === '/api/renew') {
    $in = read_body();
    exit(json_out($coord->renew(
        trim((string) ($in['key'] ?? '')),
        trim((string) ($in['owner'] ?? '')),
        (float) ($in['ttl'] ?? 10)
    )));
}

// ---- POST /api/fence-write：帶 token 寫下游資源 ----
if ($method === 'POST' && $path === '/api/fence-write') {
    $in = read_body();
    exit(json_out($coord->fenceWrite(
        trim((string) ($in['resource'] ?? 'db-row-1')),
        (int) ($in['token'] ?? 0),
        $in['payload'] ?? ''
    )));
}

// ---- POST /api/campaign：leader election ----
if ($method === 'POST' && $path === '/api/campaign') {
    $in = read_body();
    exit(json_out($coord->campaign(
        trim((string) ($in['election'] ?? 'scheduler')),
        trim((string) ($in['candidate'] ?? 'nodeA')),
        (float) ($in['ttl'] ?? 10)
    )));
}

// ---- GET /api/leader ----
if ($method === 'GET' && $path === '/api/leader') {
    exit(json_out($coord->leader(trim((string) ($_GET['election'] ?? 'scheduler')))));
}

// ---- watch：POST 註冊 / GET poll ----
if ($path === '/api/watch') {
    if ($method === 'POST') {
        $in = read_body();
        exit(json_out($coord->watch(trim((string) ($in['key'] ?? 'config:feature-flag')))));
    }
    exit(json_out($coord->pollWatch(
        trim((string) ($_GET['key'] ?? '')),
        trim((string) ($_GET['watcherId'] ?? ''))
    )));
}

// ---- config：POST put / GET get ----
if ($path === '/api/config') {
    if ($method === 'POST') {
        $in = read_body();
        exit(json_out($coord->putConfig(
            trim((string) ($in['key'] ?? 'feature-flag')),
            $in['value'] ?? ''
        )));
    }
    exit(json_out($coord->getConfig(trim((string) ($_GET['key'] ?? 'feature-flag')))));
}

// ---- POST /api/register：服務註冊 / 心跳 ----
if ($method === 'POST' && $path === '/api/register') {
    $in = read_body();
    exit(json_out($coord->register(
        trim((string) ($in['service'] ?? 'payment')),
        trim((string) ($in['instance'] ?? 'inst-1')),
        trim((string) ($in['addr'] ?? '10.0.0.1:8080')),
        (float) ($in['ttl'] ?? 10)
    )));
}

// ---- GET /api/discover ----
if ($method === 'GET' && $path === '/api/discover') {
    exit(json_out($coord->discover(trim((string) ($_GET['service'] ?? 'payment')))));
}

// ---- GET /api/snapshot ----
if ($method === 'GET' && $path === '/api/snapshot') {
    exit(json_out($coord->snapshot()));
}

// ---- GET /：測試頁 ----
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

function render_home(): void
{
    echo <<<'HTML'
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>分散式鎖 + 服務發現 · Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>
body{max-width:880px;margin:36px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:10px 14px;border-radius:0 8px 8px 0;margin:14px 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
.card{border:1px solid #444;border-radius:8px;padding:12px 16px;margin:14px 0}
.card h3{margin-top:4px}
code{background:#222;padding:2px 6px;border-radius:4px}
input,button{font-size:.95rem;padding:5px 8px;margin:2px}
input{width:120px}
button{cursor:pointer}
pre{background:#101010;border:1px solid #333;border-radius:6px;padding:10px;white-space:pre-wrap;word-break:break-all;max-height:240px;overflow:auto}
.row{display:flex;flex-wrap:wrap;align-items:center;gap:4px}
.muted{color:#888;font-size:.85rem}
</style>
</head><body>
<h1>🅻 分散式鎖 + 服務發現 · Demo</h1>
<p class="muted">單機模擬 etcd / ZooKeeper 協調原語。狀態存 <code>data/state.json</code>，所有操作走 <code>flock</code> 互斥（模擬線性一致的複製狀態機）。</p>

<div class="box"><span class="label">⭐ 推薦演示順序（最重要考點：fencing token）</span>
1) clientA acquire <code>resource-x</code>（拿到 token，例如 1）。
2) 把 ttl 設 1 秒、等它過期；clientB acquire 同 key（拿到更大 token，例如 2）。
3) clientB 用 token 2 fence-write 成功；clientA「GC 暫停後醒來」用舊 token 1 fence-write → <b>被拒</b>。
</div>

<div class="card">
  <h3>1. 分散式鎖（租約 + fencing token）</h3>
  <div class="row">
    key <input id="lk" value="resource-x"> owner <input id="lo" value="clientA"> ttl(秒) <input id="lt" value="10" style="width:60px">
    <button onclick="lock()">acquire</button>
    <button onclick="renew()">renew</button>
    <button onclick="unlock()">release</button>
  </div>
  <pre id="out_lock">（送出後顯示結果，注意回傳的 fencingToken）</pre>
</div>

<div class="card">
  <h3>2. fencing token 防護（核心考點）</h3>
  <p class="muted">下游資源只接受「比上次見過更大的 token」。舊持有者拿過期小 token 寫入會被 fence 掉。</p>
  <div class="row">
    resource <input id="fr" value="db-row-1"> token <input id="ft" value="1" style="width:60px"> payload <input id="fp" value="hello">
    <button onclick="fence()">fence-write</button>
  </div>
  <pre id="out_fence"></pre>
</div>

<div class="card">
  <h3>3. Leader Election（搶鎖即當選；租約過期他人接任）</h3>
  <div class="row">
    election <input id="el" value="scheduler"> candidate <input id="ec" value="nodeA"> ttl <input id="et" value="10" style="width:60px">
    <button onclick="campaign()">campaign</button>
    <button onclick="leader()">誰是 leader</button>
  </div>
  <pre id="out_leader"></pre>
</div>

<div class="card">
  <h3>4. Watch（變更通知佇列）</h3>
  <div class="row">
    key <input id="wk" value="config:feature-flag" style="width:200px">
    <button onclick="watchReg()">註冊 watcher</button>
    <button onclick="watchPoll()">poll 事件</button>
  </div>
  <pre id="out_watch"></pre>
</div>

<div class="card">
  <h3>5. 設定中心（put / get，put 會通知 watcher）</h3>
  <div class="row">
    key <input id="ck" value="feature-flag"> value <input id="cv" value="on">
    <button onclick="cfgPut()">put</button>
    <button onclick="cfgGet()">get</button>
  </div>
  <p class="muted">提示：先在上方對 <code>config:feature-flag</code> 註冊 watcher，再 put，回去 poll 就能看到 put 事件。</p>
  <pre id="out_cfg"></pre>
</div>

<div class="card">
  <h3>6. 服務發現（register + heartbeat / discover）</h3>
  <div class="row">
    service <input id="sv" value="payment"> instance <input id="si" value="inst-1"> addr <input id="sa" value="10.0.0.1:8080" style="width:150px"> ttl <input id="st" value="10" style="width:60px">
    <button onclick="reg()">register/heartbeat</button>
    <button onclick="disc()">discover</button>
  </div>
  <p class="muted">註冊兩個 instance（inst-1 / inst-2）後 discover；某 instance 漏心跳超過 ttl 即從清單消失。</p>
  <pre id="out_svc"></pre>
</div>

<p class="muted">※ 單機模擬協調服務；租約 / fencing token / watch / leader election / 服務發現皆為真實可執行邏輯。真實系統由 etcd/ZooKeeper 以 Raft/ZAB 多節點複製達成 CP 一致性。</p>

<script>
const j = (id,o)=>document.getElementById(id).textContent=JSON.stringify(o,null,2);
const post=(u,b)=>fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)}).then(r=>r.json());
const get=(u)=>fetch(u).then(r=>r.json());
const v=id=>document.getElementById(id).value;
let lastWatcher=null;

async function lock(){j('out_lock',await post('/api/lock',{key:v('lk'),owner:v('lo'),ttl:parseFloat(v('lt'))}))}
async function renew(){j('out_lock',await post('/api/renew',{key:v('lk'),owner:v('lo'),ttl:parseFloat(v('lt'))}))}
async function unlock(){j('out_lock',await post('/api/unlock',{key:v('lk'),owner:v('lo')}))}
async function fence(){j('out_fence',await post('/api/fence-write',{resource:v('fr'),token:parseInt(v('ft')),payload:v('fp')}))}
async function campaign(){j('out_leader',await post('/api/campaign',{election:v('el'),candidate:v('ec'),ttl:parseFloat(v('et'))}))}
async function leader(){j('out_leader',await get('/api/leader?election='+encodeURIComponent(v('el'))))}
async function watchReg(){const r=await post('/api/watch',{key:v('wk')});lastWatcher=r.watcherId;j('out_watch',r)}
async function watchPoll(){if(!lastWatcher){j('out_watch',{error:'請先註冊 watcher'});return}
  j('out_watch',await get('/api/watch?key='+encodeURIComponent(v('wk'))+'&watcherId='+lastWatcher))}
async function cfgPut(){j('out_cfg',await post('/api/config',{key:v('ck'),value:v('cv')}))}
async function cfgGet(){j('out_cfg',await get('/api/config?key='+encodeURIComponent(v('ck'))))}
async function reg(){j('out_svc',await post('/api/register',{service:v('sv'),instance:v('si'),addr:v('sa'),ttl:parseFloat(v('st'))}))}
async function disc(){j('out_svc',await get('/api/discover?service='+encodeURIComponent(v('sv'))))}
</script>
</body></html>
HTML;
}
