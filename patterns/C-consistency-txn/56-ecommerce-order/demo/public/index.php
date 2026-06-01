<?php
declare(strict_types=1);

/**
 * 電商下單系統 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8056 -t public
 *
 * 路由：
 *   GET  /                      互動測試頁（下單看 saga 狀態流轉 / 注入失敗看補償 / 冪等重送）
 *   POST /api/order             下單走 saga  { user, sku, qty, idem_key }
 *   POST /api/fail              下單但注入某步失敗 { user, sku, qty, idem_key, at: pay|confirm }
 *   POST /api/advance           推進訂單狀態 { order_id, to }（示範狀態機把關）
 *   GET  /api/order/{id}        查單（含 saga 軌跡與狀態）
 *   GET  /api/state             全局狀態（庫存 / 錢包 / 訂單）
 *   POST /api/reset             重置 demo
 */

require __DIR__ . '/../src/OrderSaga.php';

$saga = new OrderSaga(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST;
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ---- POST /api/order：正常下單（走 saga）----
if ($method === 'POST' && $path === '/api/order') {
    $b = body();
    $res = $saga->placeOrder(
        trim((string) ($b['user'] ?? 'alice')),
        trim((string) ($b['sku'] ?? 'sku-1')),
        (int) ($b['qty'] ?? 1),
        trim((string) ($b['idem_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''))),
        null
    );
    if (($res['status'] ?? '') !== 'ok' && empty($res['idempotent'])) {
        http_response_code(409);
    }
    exit(json_out($res));
}

// ---- POST /api/fail：下單但注入某步失敗（看補償回滾）----
if ($method === 'POST' && $path === '/api/fail') {
    $b = body();
    $at = trim((string) ($b['at'] ?? 'pay'));
    if (!in_array($at, ['pay', 'confirm'], true)) {
        http_response_code(400);
        exit(json_out(['error' => 'at 必須是 pay 或 confirm']));
    }
    $res = $saga->placeOrder(
        trim((string) ($b['user'] ?? 'alice')),
        trim((string) ($b['sku'] ?? 'sku-1')),
        (int) ($b['qty'] ?? 1),
        trim((string) ($b['idem_key'] ?? '')),
        $at
    );
    exit(json_out($res));   // 補償後回 200，內容含 status=cancelled 與 saga 軌跡
}

// ---- POST /api/advance：推進訂單狀態（狀態機把關）----
if ($method === 'POST' && $path === '/api/advance') {
    $b = body();
    $res = $saga->advance(
        trim((string) ($b['order_id'] ?? '')),
        trim((string) ($b['to'] ?? ''))
    );
    if (isset($res['error'])) {
        http_response_code(409);
    }
    exit(json_out($res));
}

// ---- GET /api/order/{id} ----
if ($method === 'GET' && preg_match('#^/api/order/([A-Za-z0-9_]+)$#', $path, $m)) {
    $res = $saga->getOrder($m[1]);
    if (isset($res['error'])) {
        http_response_code(404);
    }
    exit(json_out($res));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out($saga->state()));
}

// ---- POST /api/reset ----
if ($method === 'POST' && $path === '/api/reset') {
    exit(json_out($saga->reset()));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home();
    exit;
}

http_response_code(404);
echo 'not found';

function render_home(): void
{
    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>電商下單系統 · Demo</title>
<style>
body{max-width:920px;margin:36px auto;padding:0 16px;background:#0f1115;color:#e6e6e6;
  font-family:system-ui,"Microsoft JhengHei",sans-serif;line-height:1.6}
h1{font-size:1.6rem}h2{font-size:1.15rem;margin-top:1.6em;border-bottom:1px solid #333;padding-bottom:4px}
.box{border-left:4px solid #7ee787;background:#1b1f27;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
code{background:#222a36;padding:2px 6px;border-radius:4px}
button{background:#2563eb;color:#fff;border:0;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:.95rem;margin:2px}
button:hover{background:#1d4ed8}
button.warn{background:#b54708}button.warn:hover{background:#9a3d05}
button.ghost{background:#30363d}button.ghost:hover{background:#3a424b}
input,select{background:#1b1f27;border:1px solid #444;color:#e6e6e6;padding:6px 8px;border-radius:6px;width:90px}
label{display:inline-block;margin-right:14px}
pre{background:#11151c;border:1px solid #2a3140;padding:14px;border-radius:8px;overflow:auto;font-size:.82rem}
.muted{color:#8b949e;font-size:.85rem}
.card{background:#161b22;border:1px solid #2a3140;border-radius:10px;padding:16px;margin:14px 0}
.flow{display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-weight:700}
.flow span{padding:4px 10px;border-radius:6px;background:#222a36}
.flow .done{background:#1b5e20;color:#a5d6a7}.flow .cur{background:#1565c0;color:#bbdefb}
.flow .cancel{background:#7f1d1d;color:#fca5a5}
</style></head><body>
<h1>🛒 電商下單系統 · Demo</h1>
<p class="muted">綜合考點：<b>訂單狀態機（合法轉移）</b> ＋ <b>下單付款 Saga 編排與反向補償</b> ＋ <b>冪等下單</b> ＋ <b>庫存原子預扣（防超賣）</b>。
全程用 <code>flock</code> 把「讀-改-寫」原子化（模擬 DB 行鎖 / 分散式 Saga 的最終一致結果）。</p>
<div class="flow">狀態機：<span>CREATED</span>→<span>PAID</span>→<span>SHIPPED</span>→<span>DONE</span>　/　任一步可 <span class="cancel">CANCELLED</span></div>

<div class="card">
  <h2>① 正常下單（看 Saga 狀態流轉）</h2>
  <p class="muted">預扣庫存 → 扣款(→PAID) → 確認(→SHIPPED)，三步全成功。</p>
  <label>user <input id="ouser" value="alice" style="width:70px"></label>
  <label>sku <select id="osku"><option value="sku-1">sku-1 耳機</option><option value="sku-2">sku-2 鍵盤</option></select></label>
  <label>數量 <input id="oqty" type="number" value="1"></label>
  <label>idem_key <input id="oidem" value="ord-A" style="width:80px"></label>
  <p>
    <button onclick="order()">✅ 下單（saga）</button>
    <button class="ghost" onclick="order()">↩️ 用相同 idem_key 重送（看冪等）</button>
  </p>
  <pre id="oout">（按上方按鈕；重送相同 idem_key 會回原訂單、不重複扣庫存與款項）</pre>
</div>

<div class="card">
  <h2>② 注入付款 / 確認失敗（看反向補償回滾）</h2>
  <p class="muted">某一步失敗 → 反向補償已完成步驟（退款、釋放庫存）→ 訂單 CANCELLED，最終一致。</p>
  <label>sku <select id="fsku"><option value="sku-1">sku-1 耳機</option><option value="sku-2">sku-2 鍵盤</option></select></label>
  <label>數量 <input id="fqty" type="number" value="1"></label>
  <label>失敗點 <select id="fat"><option value="pay">扣款失敗(已預扣庫存)</option><option value="confirm">確認失敗(已扣款)</option></select></label>
  <p><button class="warn" onclick="fail()">💥 注入失敗下單</button></p>
  <pre id="fout">（觀察 saga 軌跡：forward 做到一半失敗 → compensate 逐步回滾 → CANCELLED）</pre>
</div>

<div class="card">
  <h2>③ 全局狀態（庫存 / 錢包 / 訂單）</h2>
  <p>
    <button onclick="state()">🔄 查全局狀態</button>
    <button class="ghost" onclick="reset()">♻️ 重置 demo</button>
  </p>
  <pre id="sout">（按「查全局狀態」：成功下單會看到庫存與錢包減少；補償後會原樣回復）</pre>
</div>

<p class="muted">API：<code>POST /api/order</code> · <code>POST /api/fail</code> · <code>POST /api/advance</code> · <code>GET /api/order/{id}</code> · <code>GET /api/state</code> · <code>POST /api/reset</code></p>

<script>
async function jpost(url, body){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});return r.json();}
function show(id,o){document.getElementById(id).textContent=JSON.stringify(o,null,2);}
function trace(o){
  if(!o.saga) return '';
  let line='狀態：'+(o.state||'?')+(o.idempotent?'（冪等命中：回原訂單）':'')+'\\n步驟軌跡：\\n';
  for(const s of o.saga){line+='  ['+s.action+'] '+s.step+' → '+s.result+'\\n';}
  return line+'\\n';
}
async function order(){
  const o=await jpost('/api/order',{user:ouser.value,sku:osku.value,qty:+oqty.value,idem_key:oidem.value});
  document.getElementById('oout').textContent=trace(o)+JSON.stringify(o,null,2);
}
async function fail(){
  const o=await jpost('/api/fail',{sku:fsku.value,qty:+fqty.value,at:fat.value,idem_key:''});
  document.getElementById('fout').textContent=trace(o)+JSON.stringify(o,null,2);
}
async function state(){const r=await fetch('/api/state');show('sout',await r.json());}
async function reset(){show('sout',await jpost('/api/reset',{}));}
</script>
</body></html>
HTML;
}
