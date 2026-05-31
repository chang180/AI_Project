<?php
declare(strict_types=1);

/**
 * Google Docs 協作編輯器 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8009 -t public
 *
 * 路由：
 *   GET  /                       測試頁（顯示文件內容 + 兩個編輯輸入：使用者 A / B）
 *   GET  /api/doc                載入文件狀態 { content, version, history }
 *   POST /api/op                 提交一個操作（OT transform 後套用、收斂）
 *                                  body: { baseVersion, kind, pos, text|len, author }
 *   POST /api/reset              重設文件（清空內容/版本/日誌）
 */

require __DIR__ . '/../src/OTEngine.php';
require __DIR__ . '/../src/DocStore.php';

$store = new DocStore(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($path, '/') ?: '/';

// ---- GET /api/doc：載入文件狀態 ----
if ($method === 'GET' && $path === '/api/doc') {
    $s = $store->load();
    exit(json_out([
        'doc_id'  => $s['doc_id'],
        'content' => $s['content'],
        'version' => $s['version'],
        'history' => $s['history'],
    ]));
}

// ---- POST /api/op：提交操作（OT 核心）----
if ($method === 'POST' && $path === '/api/op') {
    $in = read_input();
    $kind   = (string)($in['kind'] ?? '');
    $base   = (int)($in['baseVersion'] ?? 0);
    $pos    = (int)($in['pos'] ?? 0);
    $author = trim((string)($in['author'] ?? 'anon')) ?: 'anon';

    if ($kind === OTEngine::INSERT) {
        $text = (string)($in['text'] ?? '');
        if ($text === '') {
            http_response_code(400);
            exit(json_out(['error' => 'insert 需要 text']));
        }
        $op = OTEngine::insert($pos, $text);
    } elseif ($kind === OTEngine::DELETE) {
        $len = (int)($in['len'] ?? 0);
        if ($len <= 0) {
            http_response_code(400);
            exit(json_out(['error' => 'delete 需要 len > 0']));
        }
        $op = OTEngine::delete($pos, $len);
    } else {
        http_response_code(400);
        exit(json_out(['error' => 'kind 必須是 insert 或 delete']));
    }

    $result = $store->submit($base, $op, $author);
    exit(json_out([
        'ok'        => true,
        'version'   => $result['version'],   // 權威版本號（遞增）
        'content'   => $result['content'],   // transform 後的收斂內容
        'applied'   => $result['applied'],   // 實際套用的（transform 後）操作
        'submitted' => $op,                  // 客戶端原始送出的操作
        'base'      => $base,
    ]));
}

// ---- POST /api/reset：重設 ----
if ($method === 'POST' && $path === '/api/reset') {
    $store->reset();
    exit(json_out(['ok' => true]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home();
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

function read_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = (string) file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function render_home(): void
{
    echo <<<'HTML'
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Google Docs 協作編輯器 · Demo</title>
<style>
:root{--bg:#0f1419;--card:#1e2630;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--accent2:#7ee787;--warn:#f0883e}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.7;max-width:860px;margin:0 auto;padding:32px 20px 80px}
h1{font-size:1.7rem}h2{font-size:1.15rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin-top:1.6em}
code{background:#161b22;color:var(--accent2);padding:2px 6px;border-radius:6px;font-size:.9em}
.box{border-left:4px solid var(--accent);background:#1a2027;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box.tip{border-color:var(--accent2)}.box.warn{border-color:var(--warn)}
.box .label{font-weight:700;display:block;margin-bottom:.2em}
.doc{background:#161b22;border:1px solid var(--border);border-radius:10px;padding:16px 18px;font-size:1.3rem;min-height:48px;white-space:pre-wrap;word-break:break-all}
.meta{color:var(--dim);font-size:.9rem}
.editors{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:14px}
.editor{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 16px}
.editor h3{margin:.1em 0 .6em}
.editor.a h3{color:var(--accent)}.editor.b h3{color:var(--accent2)}
label{display:block;font-size:.85rem;color:var(--dim);margin:.4em 0 .15em}
input,select{width:100%;background:#0f1419;color:var(--text);border:1px solid var(--border);border-radius:6px;padding:6px 8px}
button{margin-top:.8em;background:var(--accent);color:#0b0f14;border:0;border-radius:6px;padding:8px 14px;font-weight:700;cursor:pointer}
.editor.b button{background:var(--accent2)}
.row{display:flex;gap:8px}.row>*{flex:1}
.btns{display:flex;gap:10px;margin-top:14px}
.btns button{background:#2a3540;color:var(--text)}
pre{background:#161b22;border:1px solid var(--border);border-radius:10px;padding:12px 14px;overflow:auto;font-size:.82rem;max-height:240px}
a{color:var(--accent)}
</style></head><body>
<h1>📝 Google Docs 協作編輯器 · Demo</h1>
<p class="meta">聚焦核心考點：<b>OT transform 讓並發編輯收斂</b>。讓「使用者 A」「使用者 B」基於<b>同一版本</b>同時提交 insert，伺服器 transform 後兩者都套用、結果收斂、版本號遞增。</p>

<div class="box tip"><span class="label">怎麼測收斂</span>
按「重設」清空 → 文件版本為 0。在 A、B 兩邊都把 <code>基準版本</code> 設成 <b>0</b>，A 在位置 0 插 <code>X</code>、B 在位置 0 插 <code>Y</code>，分別送出。第二個送出時其基準版本(0)已落後，伺服器自動 transform 後套用 → 兩字都在、互不覆蓋、版本變 2。
</div>

<h2>文件目前內容</h2>
<div class="doc" id="doc"></div>
<p class="meta">權威版本：<b id="ver">-</b>　文件長度：<b id="len">-</b></p>

<div class="editors">
  <div class="editor a">
    <h3>使用者 A</h3>
    <label>操作</label>
    <select id="a-kind"><option value="insert">insert 插入</option><option value="delete">delete 刪除</option></select>
    <div class="row">
      <div><label>位置 pos</label><input id="a-pos" type="number" value="0"></div>
      <div><label>基準版本 baseVersion</label><input id="a-base" type="number" value="0"></div>
    </div>
    <label>text（insert）/ len（delete）</label>
    <input id="a-val" value="X">
    <button onclick="submitOp('a')">A 送出操作</button>
  </div>
  <div class="editor b">
    <h3>使用者 B</h3>
    <label>操作</label>
    <select id="b-kind"><option value="insert">insert 插入</option><option value="delete">delete 刪除</option></select>
    <div class="row">
      <div><label>位置 pos</label><input id="b-pos" type="number" value="0"></div>
      <div><label>基準版本 baseVersion</label><input id="b-base" type="number" value="0"></div>
    </div>
    <label>text（insert）/ len（delete）</label>
    <input id="b-val" value="Y">
    <button onclick="submitOp('b')">B 送出操作</button>
  </div>
</div>

<div class="btns">
  <button onclick="reset()">重設文件</button>
  <button onclick="load()">重新載入</button>
</div>

<h2>操作日誌（已 transform 的權威操作）</h2>
<pre id="log">（尚無）</pre>

<p class="meta">※ 真實系統用 WebSocket + 完整 OT/CRDT；此 demo 用同步 HTTP 示範 OT transform 的收斂核心。詳見 <code>README.md</code>。</p>

<script>
async function load(){
  const r = await fetch('/api/doc'); const s = await r.json();
  document.getElementById('doc').textContent = s.content || '（空白）';
  document.getElementById('ver').textContent = s.version;
  document.getElementById('len').textContent = [...(s.content||'')].length;
  // 兩邊基準版本預設帶到目前版本，方便連續編輯；測並發時手動改回同一版本
  renderLog(s.history);
}
function renderLog(history){
  if(!history || !history.length){document.getElementById('log').textContent='（尚無）';return;}
  document.getElementById('log').textContent = history.map(h =>
    `v${h.version}  by ${h.author}  base=${h.base}  ` +
    `套用:${fmt(h.op)}` + (JSON.stringify(h.op)!==JSON.stringify(h.orig) ? `  (原始:${fmt(h.orig)} → transform)` : '')
  ).join('\n');
}
function fmt(op){
  return op.kind==='insert' ? `insert(pos=${op.pos},"${op.text}")` : `delete(pos=${op.pos},len=${op.len})`;
}
async function submitOp(who){
  const kind = document.getElementById(who+'-kind').value;
  const pos  = parseInt(document.getElementById(who+'-pos').value||'0',10);
  const base = parseInt(document.getElementById(who+'-base').value||'0',10);
  const val  = document.getElementById(who+'-val').value;
  const body = {kind, pos, baseVersion: base, author: who==='a'?'使用者A':'使用者B'};
  if(kind==='insert') body.text = val; else body.len = parseInt(val||'0',10);
  const r = await fetch('/api/op',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  const res = await r.json();
  if(res.error){alert(res.error);return;}
  await load();
}
async function reset(){
  await fetch('/api/reset',{method:'POST'});
  document.getElementById('a-base').value='0';
  document.getElementById('b-base').value='0';
  await load();
}
load();
</script>
</body></html>
HTML;
}
