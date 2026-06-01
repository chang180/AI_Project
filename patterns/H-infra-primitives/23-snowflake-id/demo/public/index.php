<?php
declare(strict_types=1);

/**
 * Snowflake 唯一 ID 產生器 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8023 -t public
 *
 * 路由：
 *   GET  /                    測試頁（產生單一 ID、批次展示同毫秒序號遞增、貼 ID 解碼、bit 佈局示意）
 *   POST /api/id              產生一個 ID            → { id, decoded }
 *   POST /api/id/batch        批次產生 { n }         → { count, ids:[...] }
 *   GET  /api/decode/{id}     解碼 ID                → { id, timestamp, datetime, machineId, seq }
 */

require __DIR__ . '/../src/Snowflake.php';

// 機器 id：真實系統由 ZooKeeper/etcd 分配；demo 用環境變數覆寫，預設 1。
$machineId = (int) (getenv('MACHINE_ID') ?: '1');
$sf = new Snowflake($machineId, __DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/id：產生單一 ID ----
if ($method === 'POST' && $path === '/api/id') {
    try {
        $id = $sf->nextId();
        exit(json_out(['id' => $id, 'id_str' => (string) $id, 'decoded' => Snowflake::decode($id)]));
    } catch (Throwable $e) {
        http_response_code(503);
        exit(json_out(['error' => $e->getMessage()]));
    }
}

// ---- POST /api/id/batch：批次產生 ----
if ($method === 'POST' && $path === '/api/id/batch') {
    $n = 0;
    if (($raw = file_get_contents('php://input')) !== false && $raw !== '') {
        $body = json_decode($raw, true);
        if (is_array($body)) {
            $n = (int) ($body['n'] ?? 0);
        }
    }
    if ($n === 0) {
        $n = (int) ($_POST['n'] ?? 0);
    }
    if ($n < 1) {
        $n = 10;
    }
    try {
        $ids = $sf->nextIds($n);
        $rows = array_map(static fn(int $id): array => Snowflake::decode($id), $ids);
        exit(json_out([
            'count' => count($ids),
            'ids' => array_map('strval', $ids),
            'decoded' => $rows,
        ]));
    } catch (Throwable $e) {
        http_response_code(400);
        exit(json_out(['error' => $e->getMessage()]));
    }
}

// ---- GET /api/decode/{id}：解碼 ----
if ($method === 'GET' && preg_match('#^/api/decode/(\d+)$#', $path, $m)) {
    $id = (int) $m[1];
    if ((string) $id !== $m[1]) {
        http_response_code(400);
        exit(json_out(['error' => 'id 超出 64-bit 整數範圍']));
    }
    exit(json_out(Snowflake::decode($id)));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($machineId);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function render_home(int $machineId): void
{
    $epoch = Snowflake::EPOCH;
    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Snowflake ID · Demo</title>
<style>
body{max-width:860px;margin:40px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#101418;color:#e6e6e6;line-height:1.6}
h1{margin-bottom:.2em}code{background:#222;padding:2px 6px;border-radius:4px}
button{background:#1971c2;color:#fff;border:0;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:.95rem}
button:hover{background:#1864ab}input{background:#1b1b1b;color:#eee;border:1px solid #444;border-radius:6px;padding:7px;font-size:.95rem}
.box{border-left:4px solid #7ee787;background:#181c20;padding:12px 16px;border-radius:0 8px 8px 0;margin:1.2em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
pre{background:#0c0f12;border:1px solid #333;border-radius:8px;padding:12px;overflow:auto;font-size:.85rem}
table{width:100%;border-collapse:collapse;margin-top:8px}td,th{border:1px solid #333;padding:6px 8px;text-align:left;font-size:.85rem}
.layout{display:flex;border:1px solid #444;border-radius:8px;overflow:hidden;margin:14px 0;font-size:.8rem;text-align:center}
.layout div{padding:10px 4px}
.bsign{background:#495057;flex:1}
.bts{background:#1971c2;color:#fff;flex:41}
.bm{background:#e8590c;color:#fff;flex:10}
.bseq{background:#2f9e44;color:#fff;flex:12}
.muted{color:#888;font-size:.85rem}
</style></head><body>
<h1>❄️ Snowflake 唯一 ID 產生器 · Demo</h1>
<p>分散式、無中心協調的 64-bit 唯一 ID。本機機器 id = <code>{$machineId}</code>，自訂 epoch = <code>{$epoch}</code>（2024-01-01 UTC）。</p>

<h3>64-bit 位元佈局</h3>
<div class="layout">
  <div class="bsign">1<br>sign</div>
  <div class="bts">41 bits<br>時間戳(ms)</div>
  <div class="bm">10 bits<br>機器 id</div>
  <div class="bseq">12 bits<br>序號</div>
</div>
<p class="muted">id = ((ts − EPOCH) &lt;&lt; 22) | (machineId &lt;&lt; 12) | seq　·　最高位 sign=0 → 落在 63-bit 正整數範圍</p>

<div class="box">
  <span class="label">① 產生單一 ID</span>
  <button onclick="genOne()">產生一個 ID</button>
  <pre id="one">（點上方按鈕）</pre>
</div>

<div class="box" style="border-left-color:#74c0fc">
  <span class="label">② 一次產生 N 個（觀察同毫秒內 seq 遞增）</span>
  <input id="n" type="number" value="20" min="1" max="5000" style="width:90px">
  <button onclick="genBatch()">批次產生</button>
  <div id="batch"></div>
</div>

<div class="box" style="border-left-color:#ffd43b">
  <span class="label">③ 貼一個 ID 解碼</span>
  <input id="decId" placeholder="貼上 Snowflake ID" style="width:60%">
  <button onclick="decode()">解碼</button>
  <pre id="dec">（貼上 ID 後點解碼）</pre>
</div>

<p class="muted">※ 跨請求單調性以 <code>data/snowflake_state.json</code> + <code>flock</code> 持久化模擬「單機記憶體中 synchronized 取號」。詳見 README 誠實聲明。</p>

<script>
async function genOne(){
  const r = await fetch('/api/id', {method:'POST'});
  const j = await r.json();
  document.getElementById('one').textContent = JSON.stringify(j, null, 2);
}
async function genBatch(){
  const n = document.getElementById('n').value;
  const r = await fetch('/api/id/batch', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({n: Number(n)})});
  const j = await r.json();
  if(j.error){ document.getElementById('batch').innerHTML = '<pre>'+j.error+'</pre>'; return; }
  let html = '<table><tr><th>#</th><th>id</th><th>時間</th><th>machineId</th><th>seq</th></tr>';
  j.decoded.forEach((d,i)=>{ html += `<tr><td>\${i}</td><td><code>\${j.ids[i]}</code></td><td>\${d.datetime}</td><td>\${d.machineId}</td><td><b>\${d.seq}</b></td></tr>`; });
  html += '</table><p class="muted">同一毫秒內 seq 從 0 依序遞增；跨毫秒時 seq 歸零。</p>';
  document.getElementById('batch').innerHTML = html;
}
async function decode(){
  const id = document.getElementById('decId').value.trim();
  if(!id){ return; }
  const r = await fetch('/api/decode/' + encodeURIComponent(id));
  const j = await r.json();
  document.getElementById('dec').textContent = JSON.stringify(j, null, 2);
}
</script>
</body></html>
HTML;
}
