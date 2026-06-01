<?php
declare(strict_types=1);

/**
 * 分散式鍵值儲存 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8031 -t public
 *
 * 路由：
 *   GET  /                  測試頁（環+vnode、preference list、put/get、節點 up/down、並發寫）
 *   POST /api/put           寫入 { key, value, [coordinator] }（quorum W + hinted handoff）
 *   GET  /api/get?key=      讀取（quorum R + read repair + sibling）
 *   POST /api/node          節點動作 { action: add|remove|up|down, node }
 *   GET  /api/ring          環/vnode/preference list 視圖
 *   POST /api/config        設定 { N, R, W }
 */

require __DIR__ . '/../src/Cluster.php';

$cluster = new Cluster(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- 解析 body（支援 form 與 JSON） ----
function body_param(string $name, ?string $default = null): ?string
{
    if (isset($_POST[$name])) {
        return (string) $_POST[$name];
    }
    static $json = null;
    if ($json === null) {
        $raw = (string) file_get_contents('php://input');
        $json = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    }
    return isset($json[$name]) ? (string) $json[$name] : $default;
}

function json_out(array $data, int $code = 200): string
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ---- POST /api/put ----
if ($method === 'POST' && $path === '/api/put') {
    $key = trim((string) body_param('key', ''));
    $value = (string) body_param('value', '');
    $coordinator = body_param('coordinator', null);
    $coordinator = ($coordinator !== null && trim($coordinator) !== '') ? trim($coordinator) : null;
    if ($key === '') {
        exit(json_out(['ok' => false, 'msg' => 'key 不可空'], 400));
    }
    exit(json_out($cluster->put($key, $value, $coordinator)));
}

// ---- GET /api/get ----
if ($method === 'GET' && $path === '/api/get') {
    $key = trim((string) ($_GET['key'] ?? ''));
    if ($key === '') {
        exit(json_out(['ok' => false, 'msg' => 'key 不可空'], 400));
    }
    exit(json_out($cluster->get($key)));
}

// ---- POST /api/node ----
if ($method === 'POST' && $path === '/api/node') {
    $action = trim((string) body_param('action', ''));
    $node = trim((string) body_param('node', ''));
    exit(json_out($cluster->nodeAction($action, $node)));
}

// ---- POST /api/config ----
if ($method === 'POST' && $path === '/api/config') {
    $cluster->setConfig(
        (int) body_param('N', '3'),
        (int) body_param('R', '2'),
        (int) body_param('W', '2')
    );
    exit(json_out(['ok' => true, 'config' => $cluster->config()]));
}

// ---- GET /api/ring ----
if ($method === 'GET' && $path === '/api/ring') {
    $key = trim((string) ($_GET['key'] ?? ''));
    $out = ['config' => $cluster->config(), 'ring' => $cluster->ringView(), 'hints' => $cluster->hintsView()];
    if ($key !== '') {
        $out['key'] = $key;
        $out['preferenceList'] = $cluster->preferenceList($key);
    }
    exit(json_out($out));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($cluster);
    exit;
}

exit(json_out(['error' => 'not found', 'path' => $path], 404));

// ============================ 測試頁 ============================
function render_home(Cluster $cluster): void
{
    $cfg = $cluster->config();
    $ring = $cluster->ringView();
    $nodeList = $ring['nodes'];

    // 節點晶片
    $nodeChips = '';
    foreach ($nodeList as $n) {
        $cls = $n['up'] ? 'up' : 'down';
        $label = $n['up'] ? 'UP' : 'DOWN';
        $nodeChips .= "<span class='chip $cls'>{$n['name']} · $label</span> ";
    }

    // 環上 vnode 分佈（依座標排序，色塊呈現傾斜程度）
    $ringBar = '';
    $palette = ['A' => '#1971c2', 'B' => '#2f9e44', 'C' => '#e8590c', 'D' => '#9c36b5', 'E' => '#c2255c'];
    foreach ($ring['points'] as $p) {
        $c = $palette[$p['node']] ?? '#868e96';
        $op = $p['up'] ? '1' : '0.35';
        $ringBar .= "<span class='vnode' title='point={$p['point']} → {$p['node']}' style='background:$c;opacity:$op'></span>";
    }

    $qOk = $cfg['quorumOk']
        ? "<span class='ok'>W+R &gt; N 成立（{$cfg['W']}+{$cfg['R']} &gt; {$cfg['N']}）→ 讀寫副本集合保證重疊，讀得到最新寫</span>"
        : "<span class='bad'>W+R &le; N（{$cfg['W']}+{$cfg['R']} &le; {$cfg['N']}）→ 可能讀到舊值（弱一致）</span>";

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>分散式 KV 儲存 · Demo</title>
<style>
 body{max-width:900px;margin:32px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;color:#1a1a1a;line-height:1.6}
 h1{font-size:1.5rem}h2{font-size:1.15rem;margin-top:1.8em;border-bottom:2px solid #eee;padding-bottom:4px}
 .box{border-left:4px solid #1971c2;background:#f5f9ff;padding:10px 14px;border-radius:0 8px 8px 0;margin:1em 0}
 code{background:#f0f0f0;padding:2px 6px;border-radius:4px;font-size:.9em}
 input,button,select{font-size:1rem;padding:6px 10px;border:1px solid #ccc;border-radius:6px}
 button{background:#1971c2;color:#fff;border:0;cursor:pointer}button:hover{background:#155a9c}
 button.alt{background:#495057}button.alt:hover{background:#343a40}
 .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0}
 .chip{display:inline-block;padding:3px 10px;border-radius:14px;font-size:.85rem;font-weight:700;color:#fff}
 .chip.up{background:#2f9e44}.chip.down{background:#adb5bd}
 .vnode{display:inline-block;width:10px;height:22px;margin:1px;border-radius:2px;vertical-align:middle}
 pre{background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:8px;overflow:auto;font-size:.85rem;white-space:pre-wrap}
 .ok{color:#2f9e44;font-weight:700}.bad{color:#c2255c;font-weight:700}
 small{color:#666}
</style></head><body>
<h1>🗄️ 分散式鍵值儲存 · Demo <small>(DynamoDB / Cassandra 風格)</small></h1>
<p>單一 PHP process 模擬多節點叢集。真正在跑的是：<b>一致性雜湊 + vnode</b>、<b>quorum N/R/W</b>、<b>向量時鐘衝突偵測</b>、<b>read repair</b>、<b>hinted handoff</b>。</p>

<h2>叢集設定與環狀態</h2>
<div class="box">
  N（副本數）=<b>{$cfg['N']}</b>　R（讀 quorum）=<b>{$cfg['R']}</b>　W（寫 quorum）=<b>{$cfg['W']}</b>　每節點 vnode=<b>{$cfg['vnodes']}</b><br>
  $qOk
</div>
<div class="row">節點：$nodeChips</div>
<div class="row"><small>環上 vnode 分佈（顏色=實體節點，淡=DOWN）：</small></div>
<div class="row">$ringBar</div>
<div class="row">
  設定 N/R/W：
  N <input id="cfgN" type="number" value="{$cfg['N']}" style="width:60px">
  R <input id="cfgR" type="number" value="{$cfg['R']}" style="width:60px">
  W <input id="cfgW" type="number" value="{$cfg['W']}" style="width:60px">
  <button onclick="setCfg()">套用</button>
</div>

<h2>① 查 preference list</h2>
<div class="row">
  key <input id="plKey" value="cart:42" style="width:200px">
  <button onclick="ringInfo()">查 key 落點 + preference list</button>
</div>

<h2>② 寫入 put（走 quorum W）</h2>
<div class="row">
  key <input id="pKey" value="cart:42" style="width:160px">
  value <input id="pVal" value="apple" style="width:200px">
  協調者(選填) <input id="pCoord" placeholder="自動" style="width:90px">
  <button onclick="doPut()">put</button>
</div>
<small>製造並發衝突：對同一 key，分別指定不同協調者（如 A 與 B）各寫一次不同 value，再 get 會看到兩個 sibling。</small>

<h2>③ 讀取 get（走 quorum R + read repair）</h2>
<div class="row">
  key <input id="gKey" value="cart:42" style="width:200px">
  <button onclick="doGet()">get</button>
</div>

<h2>④ 節點 up/down（看 hinted handoff）</h2>
<div class="row">
  節點 <input id="nNode" value="C" style="width:90px">
  <button class="alt" onclick="node('down')">down</button>
  <button onclick="node('up')">up</button>
  <button class="alt" onclick="node('add')">add</button>
  <button class="alt" onclick="node('remove')">remove</button>
</div>
<small>把某節點 down → 對映到它的 key 寫入會 hinted handoff 暫存到健康節點；該節點 up 後自動交還。</small>

<h2>輸出</h2>
<pre id="out">（操作結果顯示於此）</pre>

<script>
const out = document.getElementById('out');
function show(o){ out.textContent = (typeof o==='string')? o : JSON.stringify(o,null,2); }
async function api(url, opts){ const r = await fetch(url, opts); return r.json(); }
async function setCfg(){
  const b = {N:cfgN.value, R:cfgR.value, W:cfgW.value};
  show(await api('/api/config',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)}));
  setTimeout(()=>location.reload(), 400);
}
async function ringInfo(){ show(await api('/api/ring?key='+encodeURIComponent(plKey.value))); }
async function doPut(){
  const b = {key:pKey.value, value:pVal.value};
  if(pCoord.value.trim()) b.coordinator = pCoord.value.trim();
  show(await api('/api/put',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)}));
}
async function doGet(){ show(await api('/api/get?key='+encodeURIComponent(gKey.value))); }
async function node(action){
  show(await api('/api/node',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action, node:nNode.value})}));
  setTimeout(()=>location.reload(), 500);
}
</script>
<p><small>※ 誠實聲明：無真實網路 / gossip 定時心跳；一致性雜湊、quorum、向量時鐘、read repair、hinted handoff 的邏輯為真實可執行。</small></p>
</body></html>
HTML;
}
