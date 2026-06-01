<?php
declare(strict_types=1);

/**
 * LSM-tree 儲存引擎 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8032 -t public
 *
 * 路由：
 *   GET  /             測試頁（put/get/delete、看 memtable / SSTable、手動 flush / compaction、bloom 統計）
 *   POST /api/put      寫入 { key, value }
 *   POST /api/del      刪除 { key }（寫 tombstone）
 *   GET  /api/get?key= 讀取
 *   POST /api/flush    手動把 memtable flush 成 SSTable
 *   POST /api/compact  手動 compaction（合併所有 SSTable）
 *   GET  /api/state    引擎狀態快照
 */

require __DIR__ . '/../src/LsmEngine.php';

$engine = new LsmEngine(__DIR__ . '/../data', 4); // memtable 4 筆即 flush，方便 demo

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- POST /api/put ----
if ($method === 'POST' && $path === '/api/put') {
    [$key, $value] = read_kv(true);
    if ($key === '') {
        http_response_code(400);
        exit(json_out(['error' => 'key 不可為空']));
    }
    $engine->put($key, $value);
    exit(json_out(['ok' => true, 'op' => 'put', 'key' => $key, 'value' => $value, 'state' => $engine->state()]));
}

// ---- POST /api/del ----
if ($method === 'POST' && $path === '/api/del') {
    [$key] = read_kv(false);
    if ($key === '') {
        http_response_code(400);
        exit(json_out(['error' => 'key 不可為空']));
    }
    $engine->delete($key);
    exit(json_out(['ok' => true, 'op' => 'delete', 'key' => $key, 'state' => $engine->state()]));
}

// ---- GET /api/get?key= ----
if ($method === 'GET' && $path === '/api/get') {
    $key = trim((string) ($_GET['key'] ?? ''));
    if ($key === '') {
        http_response_code(400);
        exit(json_out(['error' => 'key 不可為空']));
    }
    $val = $engine->get($key);
    exit(json_out([
        'key' => $key,
        'found' => $val !== null,
        'value' => $val,
        'state' => $engine->state(),
    ]));
}

// ---- POST /api/flush ----
if ($method === 'POST' && $path === '/api/flush') {
    $did = $engine->flush();
    exit(json_out(['ok' => true, 'flushed' => $did, 'state' => $engine->state()]));
}

// ---- POST /api/compact ----
if ($method === 'POST' && $path === '/api/compact') {
    $res = $engine->compact();
    exit(json_out(['ok' => true, 'compaction' => $res, 'state' => $engine->state()]));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out($engine->state()));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($engine);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============

/**
 * 讀取 key / value：支援表單 (application/x-www-form-urlencoded) 與 JSON。
 * @return array{0:string,1:string}
 */
function read_kv(bool $wantValue): array
{
    $key = trim((string) ($_POST['key'] ?? ''));
    $value = (string) ($_POST['value'] ?? '');
    if ($key === '' && ($raw = file_get_contents('php://input'))) {
        $body = json_decode((string) $raw, true);
        if (is_array($body)) {
            $key = trim((string) ($body['key'] ?? ''));
            $value = (string) ($body['value'] ?? '');
        }
    }
    return [$key, $wantValue ? $value : ''];
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function render_home(LsmEngine $engine): void
{
    $s = $engine->state();

    $memRows = '';
    foreach ($s['memtable'] as $k => $v) {
        $memRows .= '<tr><td><code>' . htmlspecialchars((string) $k) . '</code></td><td>'
            . htmlspecialchars((string) $v) . '</td></tr>';
    }
    if ($memRows === '') {
        $memRows = '<tr><td colspan="2" style="color:#888">（空）</td></tr>';
    }

    $sstRows = '';
    foreach ($s['sstables'] as $meta) {
        $sstRows .= '<tr><td><code>' . htmlspecialchars((string) $meta['file']) . '</code></td>'
            . '<td>' . (int) $meta['count'] . '</td>'
            . '<td><code>' . htmlspecialchars((string) $meta['min_key']) . '</code> … <code>'
            . htmlspecialchars((string) $meta['max_key']) . '</code></td></tr>';
    }
    if ($sstRows === '') {
        $sstRows = '<tr><td colspan="3" style="color:#888">（尚無 SSTable，flush 後才會出現）</td></tr>';
    }

    $memSize = (int) $s['memtable_size'];
    $memLimit = (int) $s['memtable_limit'];
    $sstCount = (int) $s['sstable_count'];
    $walBytes = (int) $s['wal_bytes'];
    $bloomSkips = (int) $s['bloom_skips'];
    $sstableReads = (int) $s['sstable_reads'];

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LSM-tree 儲存引擎 · Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>
body{max-width:860px;margin:36px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
code{background:#222;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse;margin:.5em 0}
td,th{border:1px solid #444;padding:7px;text-align:left;vertical-align:top}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:.4em 0}
input{padding:6px 8px}
button{padding:6px 12px;cursor:pointer}
.stat{display:inline-block;background:#222;border-radius:6px;padding:6px 12px;margin:4px 6px 4px 0}
.stat b{color:#7ee787}
#out{background:#111;border:1px solid #333;border-radius:8px;padding:12px;white-space:pre-wrap;
     font-family:ui-monospace,monospace;font-size:.82rem;max-height:340px;overflow:auto}
h2{margin-top:1.4em}
</style></head><body>
<h1>🗃️ LSM-tree 儲存引擎 · Demo</h1>
<p>寫先進 <b>WAL + memtable</b>；memtable 滿 <code>$memLimit</code> 筆自動 <b>flush</b> 成不可變 <b>SSTable</b>。
讀由新到舊掃 SSTable，先用 <b>bloom filter</b> 跳過不可能含此 key 的檔。
<b>compaction</b> 合併 SSTable、丟棄被刪/被覆寫的舊版本。</p>

<div class="box">
  <span class="label">即時統計</span>
  <span class="stat">memtable：<b>$memSize</b> / $memLimit 筆</span>
  <span class="stat">SSTable 數：<b>$sstCount</b></span>
  <span class="stat">WAL：<b>$walBytes</b> bytes</span>
  <span class="stat">bloom 跳過：<b>$bloomSkips</b> 次</span>
  <span class="stat">實際讀 SSTable：<b>$sstableReads</b> 次</span>
</div>

<h2>操作</h2>
<div class="row">
  <input id="k" placeholder="key（如 user:1）" style="width:200px">
  <input id="v" placeholder="value（如 Alice）" style="width:200px">
  <button onclick="putKv()">PUT 寫入</button>
  <button onclick="getKv()">GET 讀取</button>
  <button onclick="delKv()">DELETE 刪除</button>
</div>
<div class="row">
  <button onclick="call('/api/flush','POST')">手動 flush（memtable → SSTable）</button>
  <button onclick="call('/api/compact','POST')">手動 compaction（合併 SSTable）</button>
  <button onclick="location.reload()">重新整理狀態</button>
</div>

<h2>memtable（記憶體有序表）</h2>
<table><tr><th>key</th><th>value</th></tr>$memRows</table>

<h2>SSTable（不可變磁碟檔，新 → 舊）</h2>
<table><tr><th>檔名</th><th>筆數</th><th>key 範圍</th></tr>$sstRows</table>

<h2>API 回應</h2>
<div id="out">點上面的按鈕看 JSON 回應…</div>

<p style="color:#888;font-size:.85rem">※ SSTable 用 JSON 檔模擬；WAL / memtable / flush / bloom filter / compaction 皆為真實邏輯，單機示意。</p>

<script>
const out = document.getElementById('out');
function show(d){ out.textContent = JSON.stringify(d, null, 2); }
async function call(url, method, body){
  const opt = { method };
  if (body){ opt.headers = {'Content-Type':'application/json'}; opt.body = JSON.stringify(body); }
  const r = await fetch(url, opt);
  const d = await r.json();
  show(d);
  setTimeout(()=>location.reload(), 400); // 操作後刷新狀態表
  return d;
}
function putKv(){
  const key = document.getElementById('k').value.trim();
  const value = document.getElementById('v').value;
  if(!key){ show({error:'請輸入 key'}); return; }
  call('/api/put','POST',{key,value});
}
function delKv(){
  const key = document.getElementById('k').value.trim();
  if(!key){ show({error:'請輸入 key'}); return; }
  call('/api/del','POST',{key});
}
async function getKv(){
  const key = document.getElementById('k').value.trim();
  if(!key){ show({error:'請輸入 key'}); return; }
  const r = await fetch('/api/get?key='+encodeURIComponent(key));
  show(await r.json());
}
</script>
</body></html>
HTML;
}
