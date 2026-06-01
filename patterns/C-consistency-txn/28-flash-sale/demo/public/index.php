<?php
declare(strict_types=1);

/**
 * 秒殺 / 搶票 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8028 -t public
 *
 * 路由：
 *   GET  /                              測試頁（設庫存、模擬搶購、看不超賣/冪等）
 *   POST /api/configure                設定場次  { name, stock, limit, token_capacity }
 *   POST /api/token                    領入場令牌 { user }
 *   POST /api/buy                      下單扣庫存 { user, idem_key, token, qty }
 *   GET  /api/stock                    查庫存
 *   POST /api/simulate                 一鍵壓測 { users, stock, limit, use_token }
 */

require __DIR__ . '/../src/FlashSale.php';

$sale = new FlashSale(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

/** 讀取 body：優先 JSON，其次表單 */
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

// ---- POST /api/configure ----
if ($method === 'POST' && $path === '/api/configure') {
    $b = body();
    $out = $sale->configure(
        trim((string) ($b['name'] ?? '場次')),
        (int) ($b['stock'] ?? 0),
        (int) ($b['limit'] ?? 1),
        (int) ($b['token_capacity'] ?? 0)
    );
    exit(json_out(['ok' => true, 'sale' => $out]));
}

// ---- POST /api/token ----
if ($method === 'POST' && $path === '/api/token') {
    $b = body();
    $user = trim((string) ($b['user'] ?? ''));
    if ($user === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user 必填']));
    }
    $res = $sale->issueToken($user);
    if (isset($res['error'])) {
        http_response_code(429); // 令牌發完 → 排隊
    }
    exit(json_out($res));
}

// ---- POST /api/buy ----
if ($method === 'POST' && $path === '/api/buy') {
    $b = body();
    $user = trim((string) ($b['user'] ?? ''));
    $idem = trim((string) ($b['idem_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')));
    $token = trim((string) ($b['token'] ?? ''));
    $qty = (int) ($b['qty'] ?? 1);
    if ($user === '') {
        http_response_code(400);
        exit(json_out(['error' => 'user 必填']));
    }
    $res = $sale->buy($user, $idem, $token, $qty);
    if (($res['status'] ?? '') !== 'ok') {
        http_response_code(409); // 賣完 / 超限 / 令牌無效
    }
    exit(json_out($res));
}

// ---- GET /api/stock ----
if ($method === 'GET' && $path === '/api/stock') {
    exit(json_out($sale->stock()));
}

// ---- POST /api/simulate ----
if ($method === 'POST' && $path === '/api/simulate') {
    $b = body();
    $res = $sale->simulate(
        (int) ($b['users'] ?? 1000),
        (int) ($b['stock'] ?? 100),
        (int) ($b['limit'] ?? 1),
        (bool) ($b['use_token'] ?? true)
    );
    exit(json_out($res));
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

function render_home(): void
{
    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>秒殺 / 搶票 · Demo</title>
<style>
body{max-width:860px;margin:36px auto;padding:0 16px;background:#0f1115;color:#e6e6e6;
  font-family:system-ui,"Microsoft JhengHei",sans-serif;line-height:1.6}
h1{font-size:1.6rem}h2{font-size:1.15rem;margin-top:1.8em;border-bottom:1px solid #333;padding-bottom:4px}
.box{border-left:4px solid #7ee787;background:#1b1f27;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
code{background:#222a36;padding:2px 6px;border-radius:4px}
button{background:#2563eb;color:#fff;border:0;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:.95rem}
button:hover{background:#1d4ed8}
input{background:#1b1f27;border:1px solid #444;color:#e6e6e6;padding:6px 8px;border-radius:6px;width:90px}
label{display:inline-block;margin-right:14px}
pre{background:#11151c;border:1px solid #2a3140;padding:14px;border-radius:8px;overflow:auto;font-size:.85rem}
.ok{color:#7ee787;font-weight:700}.bad{color:#ff7b72;font-weight:700}
.muted{color:#8b949e;font-size:.85rem}
.card{background:#161b22;border:1px solid #2a3140;border-radius:10px;padding:16px;margin:14px 0}
</style></head><body>
<h1>🎟️ 秒殺 / 搶票 Ticketmaster · Demo</h1>
<p class="muted">考點：<b>原子條件扣減防超賣</b> ＋ <b>冪等鍵去重</b> ＋ <b>每人限購</b> ＋ <b>入場令牌削峰</b>。
全程用 <code>flock</code> 把「讀-改-寫」原子化（模擬 Redis/DB 行鎖）。</p>

<div class="card">
  <h2>① 一鍵壓測模擬（重點）</h2>
  <p class="muted">M 個 user 同時搶 K 張庫存，看「售出恰為 K、不超賣」，並自動驗證同 user 同 idem_key 重送不重複扣。</p>
  <label>人數 M <input id="su" type="number" value="1000"></label>
  <label>庫存 K <input id="sk" type="number" value="100"></label>
  <label>每人限購 <input id="sl" type="number" value="1"></label>
  <label><input id="st" type="checkbox" checked style="width:auto"> 啟用入場令牌</label>
  <p><button onclick="sim()">🚀 開始模擬</button></p>
  <pre id="simout">（按上方按鈕）</pre>
</div>

<div class="card">
  <h2>② 手動下單（看冪等）</h2>
  <p class="muted">先設庫存，領令牌，再用<b>相同 idem_key</b> 重送下單，觀察只扣一次。</p>
  <p>
    <label>庫存 <input id="cstock" type="number" value="3"></label>
    <label>限購 <input id="climit" type="number" value="2"></label>
    <label>令牌上限 <input id="ccap" type="number" value="5"></label>
    <button onclick="cfg()">設定場次</button>
  </p>
  <p>
    <label>user <input id="buser" type="text" value="alice" style="width:80px"></label>
    <label>idem_key <input id="bidem" type="text" value="key-1" style="width:90px"></label>
    <button onclick="tok()">領令牌</button>
    <button onclick="buy()">下單</button>
    <button onclick="stock()">查庫存</button>
  </p>
  <pre id="manout">（操作結果）</pre>
</div>

<p class="muted">API：<code>POST /api/configure</code> · <code>POST /api/token</code> · <code>POST /api/buy</code> · <code>GET /api/stock</code> · <code>POST /api/simulate</code></p>

<script>
let lastToken = '';
async function jpost(url, body){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});return r.json();}
function show(id,o){document.getElementById(id).textContent=JSON.stringify(o,null,2);}

async function sim(){
  document.getElementById('simout').textContent='模擬中…';
  const o=await jpost('/api/simulate',{
    users:+su.value, stock:+sk.value, limit:+sl.value, use_token:st.checked});
  const v=o.verify;
  const head='售出='+v.final_sold+'　剩餘='+v.final_remaining+
    '　無超賣：'+(v.no_oversell?'✅':'❌')+
    '　售出==庫存：'+(v.sold_equals_stock?'✅':'❌')+
    '　冪等：'+(v.idempotency&&v.idempotency.same_order&&v.idempotency.stock_unchanged?'✅':'—')+'\\n\\n';
  document.getElementById('simout').textContent=head+JSON.stringify(o,null,2);
}
async function cfg(){show('manout',await jpost('/api/configure',{name:'手動場次',stock:+cstock.value,limit:+climit.value,token_capacity:+ccap.value}));}
async function tok(){const o=await jpost('/api/token',{user:buser.value});lastToken=o.token||'';show('manout',o);}
async function buy(){show('manout',await jpost('/api/buy',{user:buser.value,idem_key:bidem.value,token:lastToken,qty:1}));}
async function stock(){const r=await fetch('/api/stock');show('manout',await r.json());}
</script>
</body></html>
HTML;
}
