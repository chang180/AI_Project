<?php
declare(strict_types=1);

/**
 * 分散式快取 (Redis Cluster) Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8034 -t public
 *
 * 路由：
 *   GET  /                測試頁（set/get、slot→節點映射、加/移除節點看 slot 搬移、
 *                                 切 LRU/LFU + maxmemory 看淘汰、觸發穿透/擊穿/雪崩 + 緩解）
 *   POST /api/set         set { key, value, ttl }            → 看 slot / shard / 是否淘汰誰
 *   GET  /api/get         get ?key=                          → 看 slot / shard / 命中
 *   POST /api/policy      切換淘汰策略 { policy:lru|lfu|noeviction, maxKeys }
 *   POST /api/node        加/移除節點 { action:add|remove|failover, node }
 *   GET  /api/slots       slot→節點 映射表 + 各 shard 概觀（也可 ?key= 查某 key 路由）
 *   POST /api/scenario    三大問題示範 { type:penetration|breakdown|avalanche, mitigate:bool, n }
 */

require __DIR__ . '/../src/Cluster.php';

$cluster = new Cluster(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- POST /api/set ----
if ($method === 'POST' && $path === '/api/set') {
    $in = read_body();
    $key = trim((string) ($in['key'] ?? ''));
    if ($key === '') {
        http_response_code(400);
        exit(json_out(['error' => 'key 不可為空']));
    }
    $value = $in['value'] ?? '';
    $ttl = isset($in['ttl']) && $in['ttl'] !== '' ? (int) $in['ttl'] : null;
    $res = $cluster->set($key, $value, $ttl);
    $res['note'] = 'slot = crc32(key) % 16384 → 查 slotmap 得 shard → 寫該 shard 的 master 並同步 replica';
    exit(json_out($res));
}

// ---- GET /api/get ----
if ($method === 'GET' && $path === '/api/get') {
    $key = trim((string) ($_GET['key'] ?? ''));
    if ($key === '') {
        http_response_code(400);
        exit(json_out(['error' => 'key 不可為空']));
    }
    exit(json_out($cluster->get($key)));
}

// ---- POST /api/policy ----
if ($method === 'POST' && $path === '/api/policy') {
    $in = read_body();
    $policy = (string) ($in['policy'] ?? 'lru');
    $maxKeys = (int) ($in['maxKeys'] ?? 100);
    $cluster->setPolicy($policy, $maxKeys);
    exit(json_out([
        'policy' => $cluster->policy(),
        'note' => 'maxKeys 滿時依策略淘汰：lru=最久未用、lfu=最少使用、noeviction=拒寫',
    ]));
}

// ---- POST /api/node ----
if ($method === 'POST' && $path === '/api/node') {
    $in = read_body();
    $action = (string) ($in['action'] ?? 'add');
    $node = trim((string) ($in['node'] ?? ''));
    if ($node === '') {
        http_response_code(400);
        exit(json_out(['error' => 'node 不可為空']));
    }
    $res = match ($action) {
        'add' => $cluster->addNode($node),
        'remove' => $cluster->removeNode($node),
        'failover' => $cluster->failover($node),
        default => ['error' => '未知 action（add|remove|failover）'],
    };
    $res['ranges'] = $cluster->slotMap()->ranges();
    exit(json_out($res));
}

// ---- GET /api/slots ----
if ($method === 'GET' && $path === '/api/slots') {
    $key = trim((string) ($_GET['key'] ?? ''));
    $out = $cluster->clusterInfo();
    if ($key !== '') {
        $out['routeForKey'] = $cluster->route($key);
    }
    exit(json_out($out));
}

// ---- POST /api/scenario ----
if ($method === 'POST' && $path === '/api/scenario') {
    $in = read_body();
    $type = (string) ($in['type'] ?? 'penetration');
    $mitigate = (bool) ($in['mitigate'] ?? false);
    $n = (int) ($in['n'] ?? 5);
    $n = max(1, min(50, $n));
    $res = match ($type) {
        'penetration' => $cluster->scenarioPenetration($n, $mitigate),
        'breakdown' => $cluster->scenarioBreakdown($n, $mitigate),
        'avalanche' => $cluster->scenarioAvalanche($n, $mitigate),
        default => ['error' => '未知 type（penetration|breakdown|avalanche）'],
    };
    exit(json_out($res));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($cluster);
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
    return (string) json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
}

function render_home(Cluster $cluster): void
{
    $info = $cluster->clusterInfo();
    $rangeRows = '';
    foreach ($info['ranges'] as $r) {
        $rangeRows .= '<tr><td><code>' . htmlspecialchars((string) $r['node']) . '</code></td>'
            . '<td>' . (int) $r['start'] . ' – ' . (int) $r['end'] . '</td>'
            . '<td>' . (int) $r['count'] . '</td></tr>';
    }
    $pol = $info['policy'];
    $maxK = $info['maxKeysPerShard'];

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>分散式快取 (Redis Cluster) · Demo</title>
<style>
body{max-width:920px;margin:36px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#16181c;color:#e6e6e6;line-height:1.6}
h1{font-size:1.5rem}h2{font-size:1.1rem;margin-top:1.8em;border-bottom:1px solid #333;padding-bottom:.3em}
code{background:#222;padding:2px 6px;border-radius:4px;color:#7ee787}
.box{border-left:4px solid #58a6ff;background:#1b1d22;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
table{width:100%;border-collapse:collapse;margin:.6em 0}td,th{border:1px solid #333;padding:7px 9px;text-align:left;font-size:.92rem}
th{background:#20242b}
input,select,button{font-size:.92rem;padding:6px 8px;border-radius:6px;border:1px solid #444;background:#0f1115;color:#e6e6e6;margin:2px}
button{background:#1f6feb;border-color:#1f6feb;cursor:pointer}button:hover{background:#388bfd}
.row{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:.4em 0}
pre{background:#0f1115;border:1px solid #333;border-radius:8px;padding:12px;overflow:auto;font-size:.85rem;max-height:360px}
label{font-size:.85rem;color:#aab}
.pill{display:inline-block;background:#20242b;border:1px solid #333;border-radius:999px;padding:2px 10px;font-size:.8rem;margin-right:6px}
</style>
</head><body>
<h1>🅺 分散式快取 (Redis Cluster) · Demo</h1>
<p>真實邏輯：<code>slot = crc32(key) % 16384</code> 路由到 shard（仿 Redis Cluster 16384 slot）、每 shard <strong>master+replica</strong>、<strong>LRU/LFU</strong> 淘汰、<strong>穿透/擊穿/雪崩</strong> 三大問題與緩解。</p>
<p><span class="pill">目前策略：$pol</span><span class="pill">每 shard 上限：$maxK 鍵</span><span class="pill">slot 總數：16384</span></p>

<h2>slot → 節點 映射（目前）</h2>
<table><tr><th>shard</th><th>slot 區段</th><th>slot 數</th></tr>$rangeRows</table>

<h2>① set / get（看 key 落在哪個 slot / shard）</h2>
<div class="row">
  key <input id="k" value="user-42" size="12">
  value <input id="v" value="Alice" size="12">
  TTL秒 <input id="ttl" value="60" size="4">
  <button onclick="doSet()">set</button>
  <button onclick="doGet()">get</button>
  <button onclick="route()">只看路由</button>
</div>
<div class="box"><span class="label">提示</span>同一個 key 的 slot 固定不變；換 key 會落到不同 slot/shard。試試 <code>user-42</code>、<code>order-99</code>、<code>{tag}:a</code> 與 <code>{tag}:b</code>（後兩者用 hash tag 會落同一 slot）。</div>

<h2>② 加 / 移除節點（看哪些 slot 換主 = 重分片）</h2>
<div class="row">
  node <input id="node" value="shardD" size="10">
  <button onclick="node('add')">加入節點</button>
  <button onclick="node('remove')">移除節點</button>
  <button onclick="node('failover')">故障轉移(replica升主)</button>
</div>
<div class="box"><span class="label">重點</span>加節點時只「搬移」一批 slot 給新節點，<strong>不是全表重排</strong>（這正是 16384 slot 相對「取模 N」的優勢）。輸出的 <code>moved</code> 就是換主的 slot 區段。</div>

<h2>③ 淘汰策略 + maxmemory（看淘汰誰）</h2>
<div class="row">
  <select id="policy"><option value="lru">LRU 最久未用</option><option value="lfu">LFU 最少使用</option><option value="noeviction">noeviction 拒寫</option></select>
  每 shard 上限 <input id="maxKeys" value="3" size="4">
  <button onclick="setPolicy()">套用策略</button>
  <button onclick="slots()">看叢集狀態</button>
</div>
<div class="box"><span class="label">怎麼觸發</span>把上限設小（如 3），對<strong>同一個 shard</strong> 連續 set 多個 key（讓它超過上限），輸出的 <code>evicted</code> 會顯示被淘汰的 key。先 get 某個 key 幾次可拉高它的 freq/新鮮度，觀察 LRU 與 LFU 淘汰對象不同。</div>

<h2>④ 三大快取問題 + 緩解</h2>
<div class="row">
  次數/數量 n <input id="n" value="6" size="4">
  <label><input type="checkbox" id="mit"> 開啟緩解</label>
</div>
<div class="row">
  <button onclick="scenario('penetration')">穿透 penetration</button>
  <button onclick="scenario('breakdown')">擊穿 breakdown</button>
  <button onclick="scenario('avalanche')">雪崩 avalanche</button>
</div>
<div class="box"><span class="label">看什麼</span>
  <strong>穿透</strong>：查不存在的 key，看 <code>dbCalls</code>（緩解關=每次都打 DB；開=空值快取+布隆只打極少次）。
  <strong>擊穿</strong>：熱 key 過期併發回源，看 <code>dbCalls</code>（緩解關=併發數；開 singleflight=約 1）。
  <strong>雪崩</strong>：大量 key 過期，看 <code>spreadSeconds</code>（緩解關=擠在同一秒；開抖動=散開）。
</div>

<h2>輸出</h2>
<pre id="out">（操作結果顯示於此）</pre>

<p style="color:#888;font-size:.82rem;margin-top:2em">※ 誠實聲明：單機用檔案模擬多個 shard，無真正網路/叢集；slot 用 <code>crc32 % 16384</code> 近似 Redis 的 <code>crc16</code>。但 16384 slot 路由、重分片只搬受影響 slot、主從複製、LRU/LFU 淘汰、穿透/擊穿/雪崩三問題與緩解，邏輯皆為真實可執行。</p>

<script>
const out = document.getElementById('out');
function show(o){ out.textContent = JSON.stringify(o, null, 2); }
async function post(url, body){ const r = await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); return r.json(); }
async function get(url){ const r = await fetch(url); return r.json(); }
function val(id){ return document.getElementById(id).value; }

async function doSet(){
  const ttl = val('ttl');
  show(await post('/api/set',{key:val('k'),value:val('v'),ttl:ttl===''?null:+ttl}));
}
async function doGet(){ show(await get('/api/get?key='+encodeURIComponent(val('k')))); }
async function route(){ show(await get('/api/slots?key='+encodeURIComponent(val('k')))); }
async function node(action){ show(await post('/api/node',{action,node:val('node')})); }
async function setPolicy(){ show(await post('/api/policy',{policy:val('policy'),maxKeys:+val('maxKeys')})); }
async function slots(){ show(await get('/api/slots')); }
async function scenario(type){ show(await post('/api/scenario',{type,mitigate:document.getElementById('mit').checked,n:+val('n')})); }
</script>
</body></html>
HTML;
}
