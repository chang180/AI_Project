<?php
declare(strict_types=1);

/**
 * 資料庫分片 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8046 -t public
 *
 * 路由：
 *   GET  /                測試頁（設分片數、插入看落哪片、依 key 單片點查、跨片聚合、加分片 re-shard）
 *   POST /api/insert      插入 { shard_key, amount?, region? } → 路由到 crc32 % N 那片 + 配全域 ID
 *   GET  /api/get?key=    依 shard_key 單片點查（只打一台分片）
 *   GET  /api/aggregate   跨分片 scatter-gather（op=count|sum|top, field=amount, limit=）
 *   POST /api/addshard    re-shard：加一台分片，回報哪些 key 換家、搬遷比例
 *   POST /api/config      設定 { shards }（重置資料）
 *   GET  /api/overview    各分片列數、樣本資料
 */

require __DIR__ . '/../src/ShardRouter.php';

$router = new ShardRouter(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

/** 解析 body（支援 form 與 JSON） */
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

// ---- POST /api/insert ----
if ($method === 'POST' && $path === '/api/insert') {
    $key = trim((string) body_param('shard_key', ''));
    if ($key === '') {
        exit(json_out(['ok' => false, 'msg' => 'shard_key 不可空'], 400));
    }
    $extra = [];
    $amount = body_param('amount', null);
    if ($amount !== null && trim($amount) !== '') {
        $extra['amount'] = (float) $amount;
    }
    $region = body_param('region', null);
    if ($region !== null && trim($region) !== '') {
        $extra['region'] = trim($region);
    }
    exit(json_out($router->insert($key, $extra)));
}

// ---- GET /api/get ----
if ($method === 'GET' && $path === '/api/get') {
    $key = trim((string) ($_GET['key'] ?? ''));
    if ($key === '') {
        exit(json_out(['ok' => false, 'msg' => 'key 不可空'], 400));
    }
    exit(json_out($router->getByKey($key)));
}

// ---- GET /api/aggregate ----
if ($method === 'GET' && $path === '/api/aggregate') {
    exit(json_out($router->aggregate([
        'op'    => (string) ($_GET['op'] ?? 'count'),
        'field' => (string) ($_GET['field'] ?? 'amount'),
        'limit' => (int) ($_GET['limit'] ?? 5),
    ])));
}

// ---- POST /api/addshard ----
if ($method === 'POST' && $path === '/api/addshard') {
    exit(json_out($router->addShard()));
}

// ---- POST /api/config ----
if ($method === 'POST' && $path === '/api/config') {
    $router->setShards((int) body_param('shards', '2'));
    exit(json_out(['ok' => true, 'overview' => $router->overview()]));
}

// ---- GET /api/overview ----
if ($method === 'GET' && $path === '/api/overview') {
    exit(json_out($router->overview()));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($router);
    exit;
}

exit(json_out(['error' => 'not found', 'path' => $path], 404));

// ============================ 測試頁 ============================
function render_home(ShardRouter $router): void
{
    $ov = $router->overview();
    $n = (int) $ov['shards'];

    // 分片列數晶片
    $shardChips = '';
    $palette = ['#1971c2', '#2f9e44', '#e8590c', '#9c36b5', '#c2255c', '#0c8599', '#e67700', '#5f3dc4'];
    foreach ($ov['per_shard'] as $sh) {
        $c = $palette[$sh['shard'] % count($palette)];
        $shardChips .= "<span class='chip' style='background:$c'>shard {$sh['shard']} · {$sh['rows']} 列</span> ";
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>資料庫分片 · Demo</title>
<style>
 body{max-width:920px;margin:32px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;color:#1a1a1a;line-height:1.6}
 h1{font-size:1.5rem}h2{font-size:1.15rem;margin-top:1.8em;border-bottom:2px solid #eee;padding-bottom:4px}
 .box{border-left:4px solid #1971c2;background:#f5f9ff;padding:10px 14px;border-radius:0 8px 8px 0;margin:1em 0}
 code{background:#f0f0f0;padding:2px 6px;border-radius:4px;font-size:.9em}
 input,button,select{font-size:1rem;padding:6px 10px;border:1px solid #ccc;border-radius:6px}
 button{background:#1971c2;color:#fff;border:0;cursor:pointer}button:hover{background:#155a9c}
 button.alt{background:#495057}button.alt:hover{background:#343a40}
 .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0}
 .chip{display:inline-block;padding:3px 10px;border-radius:14px;font-size:.85rem;font-weight:700;color:#fff}
 pre{background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:8px;overflow:auto;font-size:.85rem;white-space:pre-wrap}
 small{color:#666}
</style></head><body>
<h1>🧩 資料庫分片 · Demo <small>(Vitess / ProxySQL 風格)</small></h1>
<p>單一 PHP process，<b>每個分片一個 JSON 檔</b>假裝成一台 MySQL。真正在跑的考點：
<b>取模分片路由</b>、<b>scatter-gather 跨片聚合</b>、<b>分散式全域 ID（號段）</b>、<b>re-shard 搬遷</b>。</p>

<div class="box">
  目前分片數 N = <b>{$n}</b>　號段大小 = <b>{$ov['segment']}</b>（全域 ID = shard × 號段 + 本地序號，跨片不撞號）<br>
  <div class="row" style="margin-top:6px">$shardChips</div>
</div>

<h2>① 設定分片數（會清空資料重來）</h2>
<div class="row">
  分片數 N <input id="cfgN" type="number" value="{$n}" min="1" max="8" style="width:70px">
  <button onclick="setCfg()">套用</button>
  <small>建議從 2 起，插一批資料後再用下面「加分片」看 re-shard 搬遷量。</small>
</div>

<h2>② 插入資料（依 shard_key 路由）</h2>
<div class="row">
  shard_key <input id="iKey" value="user:1001" style="width:160px">
  amount <input id="iAmt" value="120" style="width:90px">
  region <input id="iReg" value="TW" style="width:80px">
  <button onclick="doInsert()">insert</button>
  <button class="alt" onclick="seed()">一鍵塞 12 筆樣本</button>
</div>
<small>回傳會顯示 <code>crc32(key) % N</code> 算出落哪片，以及配到的全域 ID。</small>

<h2>③ 依 key 點查（單片，最快）</h2>
<div class="row">
  shard_key <input id="gKey" value="user:1001" style="width:200px">
  <button onclick="doGet()">get（只打 1 片）</button>
</div>

<h2>④ 跨片聚合 scatter-gather（沒帶 shard_key → 打全部片）</h2>
<div class="row">
  <button onclick="agg('count')">count（全表筆數）</button>
  <button onclick="agg('sum')">sum(amount)</button>
  <button onclick="agg('top')">top 5 (依 amount)</button>
</div>
<small>注意回傳的 <code>shards_scanned</code>＝走訪片數＝N，凸顯跨片查詢要打所有分片再合併。</small>

<h2>⑤ 加一台分片 re-shard（看哪些 key 換家）</h2>
<div class="row">
  <button class="alt" onclick="addShard()">加 1 片（N → N+1）</button>
  <small>取模分片加機器幾乎全搬；回傳會給「搬遷比例」與換家明細。</small>
</div>

<h2>輸出</h2>
<pre id="out">（操作結果顯示於此）</pre>

<script>
const out = document.getElementById('out');
function show(o){ out.textContent = (typeof o==='string')? o : JSON.stringify(o,null,2); }
async function api(url, opts){ const r = await fetch(url, opts); return r.json(); }
function post(url, body){ return api(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body||{})}); }

async function setCfg(){ show(await post('/api/config',{shards:cfgN.value})); setTimeout(()=>location.reload(),400); }
async function doInsert(){
  show(await post('/api/insert',{shard_key:iKey.value, amount:iAmt.value, region:iReg.value}));
  setTimeout(()=>location.reload(),400);
}
async function doGet(){ show(await api('/api/get?key='+encodeURIComponent(gKey.value))); }
async function agg(op){ show(await api('/api/aggregate?op='+op+'&field=amount&limit=5')); }
async function addShard(){ show(await post('/api/addshard',{})); setTimeout(()=>location.reload(),600); }
async function seed(){
  const rows = [
    ['user:1001',120,'TW'],['user:1002',88,'TW'],['user:1003',230,'JP'],['user:1004',45,'US'],
    ['user:1005',310,'JP'],['user:1006',75,'TW'],['user:1007',199,'US'],['user:1008',60,'TW'],
    ['user:1009',420,'JP'],['user:1010',15,'US'],['user:1011',150,'TW'],['user:1012',95,'JP']
  ];
  for(const [k,a,r] of rows){ await post('/api/insert',{shard_key:k, amount:a, region:r}); }
  show('已塞入 12 筆樣本，重新整理看各分片分佈。'); setTimeout(()=>location.reload(),500);
}
</script>
<p><small>※ 誠實聲明：JSON 檔模擬多台 MySQL；路由 / scatter-gather / 全域 ID / re-shard 的邏輯為真實可執行。</small></p>
</body></html>
HTML;
}
