<?php
declare(strict_types=1);

/**
 * 即時詐欺偵測 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8059 -t public
 *
 * 管線：每一筆交易 ─▶ 即時特徵(滑動視窗速度，呼應 ㉒) ─▶ 規則引擎(多條規則加權評分)
 *                  ─▶ 風險總分 ─▶ 三分決策 allow / review / deny
 *
 * 路由：
 *   GET  /                 深色測試頁（送正常交易看放行、灌爆同卡/同IP觸發速度規則、調門檻）
 *   POST /api/evaluate     評估一筆或一批交易，回每筆分數 + 觸發了哪些規則 + 決策
 *   GET  /api/state        看目前設定（門檻/權重）、黑名單、滑動視窗狀態、決策統計
 *   POST /api/config       即時調整規則門檻 / 權重 / 決策分界，當場看決策變化
 *   POST /api/reset        清空滑動視窗 / 黑名單 / 統計，回預設設定
 */

require __DIR__ . '/../src/SlidingWindow.php';
require __DIR__ . '/../src/RuleEngine.php';

// ---- 狀態持久化（JSON 檔模擬線上特徵庫的 keyed-state；跨請求保留速度視窗與設定）----
$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}
$stateFile = $dataDir . '/state.json';

$cfg = [];
$savedEngineState = [];
$stats = ['allow' => 0, 'review' => 0, 'deny' => 0, 'total' => 0];

if (is_file($stateFile)) {
    $s = json_decode((string) file_get_contents($stateFile), true) ?: [];
    $cfg = $s['engine']['cfg'] ?? [];
    $savedEngineState = $s['engine'] ?? [];
    $stats = $s['stats'] ?? $stats;
}

$engine = new RuleEngine($cfg);
$engine->load($savedEngineState);

function save_state(string $file, RuleEngine $engine, array $stats): void
{
    file_put_contents($file, json_encode([
        'engine' => $engine->snapshot(),
        'stats' => $stats,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

/**
 * 評估一批交易：逐筆過規則引擎，累計三分決策統計。
 * @return array{results:array,stats:array}
 */
function run_batch(array $txns, RuleEngine $engine, array &$stats): array
{
    $results = [];
    foreach ($txns as $t) {
        if (!is_array($t)) {
            continue;
        }
        $r = $engine->evaluate($t);
        $results[] = $r;
        $stats[$r['decision']] = ($stats[$r['decision']] ?? 0) + 1;
        $stats['total'] = ($stats['total'] ?? 0) + 1;
    }
    return ['results' => $results, 'stats' => $stats];
}

// ---- POST /api/evaluate：評估一筆或一批交易 ----
if ($method === 'POST' && $path === '/api/evaluate') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        exit(json_out(['error' => 'body 需為交易物件，或 {transactions:[...]}']));
    }
    // 支援單筆物件、{transactions:[...]}、或直接陣列
    if (isset($body['transactions']) && is_array($body['transactions'])) {
        $txns = $body['transactions'];
    } elseif (isset($body['txn_id']) || isset($body['card_id']) || isset($body['amount'])) {
        $txns = [$body];
    } else {
        $txns = $body;
    }
    $now = time();
    foreach ($txns as &$t) {
        if (is_array($t) && !isset($t['ts'])) {
            $t['ts'] = $now; // 未帶時間戳者用當下時間，速度視窗才有意義
        }
    }
    unset($t);
    $out = run_batch($txns, $engine, $stats);
    save_state($stateFile, $engine, $stats);
    exit(json_out([
        'results' => $out['results'],
        'stats' => $stats,
    ]));
}

// ---- POST /api/config：即時調整門檻 / 權重 / 決策分界 ----
if ($method === 'POST' && $path === '/api/config') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        exit(json_out(['error' => 'body 需為設定鍵值物件']));
    }
    // 黑名單可一併在 config 內新增（blacklist:["...",...]）
    $newBlacklist = [];
    if (isset($body['blacklist']) && is_array($body['blacklist'])) {
        $newBlacklist = $body['blacklist'];
        unset($body['blacklist']);
    }
    $mergedCfg = array_merge($engine->config(), $body);
    // 重建引擎以套用新設定（保留滑動視窗歷史與既有黑名單）
    $prev = $engine->snapshot();
    $engine = new RuleEngine($mergedCfg);
    $engine->load([
        'card_vel' => $prev['card_vel'] ?? [],
        'ip_vel' => $prev['ip_vel'] ?? [],
        'blacklist' => $prev['blacklist'] ?? [],
    ]);
    $engine->loadBlacklist($newBlacklist);
    save_state($stateFile, $engine, $stats);
    exit(json_out([
        'ok' => true,
        'config' => $engine->config(),
        'blacklist' => $engine->blacklistValues(),
    ]));
}

// ---- POST /api/reset：清空 ----
if ($method === 'POST' && $path === '/api/reset') {
    $engine->reset();
    $stats = ['allow' => 0, 'review' => 0, 'deny' => 0, 'total' => 0];
    @unlink($stateFile);
    exit(json_out(['ok' => true]));
}

// ---- GET /api/state：看目前狀態 ----
if ($method === 'GET' && $path === '/api/state') {
    $snap = $engine->snapshot();
    exit(json_out([
        'config' => $engine->config(),
        'blacklist' => $engine->blacklistValues(),
        'stats' => $stats,
        'card_velocity_keys' => count($snap['card_vel'] ?? []),
        'ip_velocity_keys' => count($snap['ip_vel'] ?? []),
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
<title>即時詐欺偵測 · Demo</title>
<style>
body{max-width:880px;margin:36px auto;padding:0 16px;background:#0f1419;color:#e6edf3;
  font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.7}
h1{font-size:1.6rem}h2{font-size:1.15rem;border-bottom:1px solid #2a3540;padding-bottom:.3em;margin-top:1.6em}
a{color:#58a6ff}code{background:#161b22;padding:2px 6px;border-radius:6px;color:#7ee787}
button{background:#1a2027;color:#e6edf3;border:1px solid #2a3540;border-radius:8px;
  padding:8px 16px;cursor:pointer;font-size:.95rem;margin:4px 6px 4px 0}
button:hover{border-color:#58a6ff}
.box{border-left:4px solid #58a6ff;background:#1a2027;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box.warn{border-color:#f0883e}.box.tip{border-color:#7ee787}
.box .label{font-weight:700;display:block;margin-bottom:.2em}
pre{background:#161b22;border:1px solid #2a3540;border-radius:12px;padding:14px 16px;overflow-x:auto;font-size:.85rem}
label{display:inline-block;min-width:150px}
input{background:#161b22;color:#e6edf3;border:1px solid #2a3540;border-radius:6px;padding:5px 8px;width:90px}
.tag{display:inline-block;padding:1px 8px;border-radius:999px;font-size:.8rem;font-weight:700}
.tag.allow{background:#1b3a26;color:#7ee787}.tag.review{background:#3a2f12;color:#f2cc60}.tag.deny{background:#3a1b1b;color:#ff7b72}
</style></head><body>
<h1>🛡️ 即時詐欺偵測 · Demo</h1>
<p>管線：<code>每筆交易 → 即時特徵(滑動視窗速度) → 規則引擎多條加權評分 → 風險總分 → 三分決策 allow / review / deny</code><br>
聚焦 <strong>即時規則引擎 + 滑動視窗速度特徵 + 分數三分決策</strong>，非 CRUD。</p>

<div class="box tip"><span class="label">① 送一連串「正常」交易（多半放行）</span>
不同卡、不同 IP、小額交易，速度規則不會觸發，分數低 → 多數 <span class="tag allow">allow</span>。</div>
<button onclick="normal()">▶ 送 5 筆正常交易</button>

<div class="box warn"><span class="label">② 灌爆同一張卡（觸發速度規則 → review / deny）</span>
同一張卡 <code>card_FLOOD</code> 在 1 秒內連送 8 筆，速度滑動視窗筆數暴增 → 觸發 <code>velocity_card</code> 規則加分 → 分數升高，後段交易被判 <span class="tag review">review</span> 甚至 <span class="tag deny">deny</span>。</div>
<button onclick="flood()">💥 同卡連送 8 筆</button>

<h2>③ 調門檻（即時生效）</h2>
<div>
  <label>同卡速度門檻 velocity_card_max</label><input id="cardMax" type="number" value="3"><br>
  <label>金額門檻 amount_max</label><input id="amtMax" type="number" value="1000"><br>
  <label>review 分界 review_at</label><input id="reviewAt" type="number" value="50"><br>
  <label>deny 分界 deny_at</label><input id="denyAt" type="number" value="90"><br>
  <button onclick="config()">套用設定</button>
  <button onclick="state()">看目前狀態</button>
  <button onclick="reset()">🗑 清空</button>
</div>

<h2>結果</h2>
<pre id="out">（點上面按鈕）</pre>

<div class="box warn"><span class="label">誠實聲明</span>
Demo 為<strong>單機單行程</strong>，用 JSON 檔保存滑動視窗與設定以示意，並非真接 Kafka / Flink / 特徵庫 / 線上模型服務；
但 <strong>滑動視窗速度特徵、多條規則加權評分、風險總分三分決策（allow/review/deny）、即時調門檻</strong> 皆為真實可執行的邏輯。
其中 <code>model</code> 規則的「模型風險分」為<strong>加權示意</strong>（金額/速度越高分越高），非真正訓練出的 ML 模型；規則引擎與滑動視窗為真。</div>

<script>
const out = document.getElementById('out');
const show = d => out.textContent = JSON.stringify(d, null, 2);
function now(){ return Math.floor(Date.now()/1000); }
async function evaluate(txns){
  return (await (await fetch('/api/evaluate',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({transactions:txns})})).json());
}
async function normal(){
  const t = now(), txns=[];
  for(let i=0;i<5;i++) txns.push({txn_id:'n'+i,card_id:'card_'+i,ip:'10.0.0.'+i,device:'d'+i,amount:120+i*30,ts:t});
  show(await evaluate(txns));
}
async function flood(){
  const t = now(), txns=[];
  for(let i=0;i<8;i++) txns.push({txn_id:'f'+i,card_id:'card_FLOOD',ip:'66.66.66.66',device:'dF',amount:300,ts:t});
  show(await evaluate(txns));
}
async function config(){
  const body={
    velocity_card_max:Number(document.getElementById('cardMax').value),
    amount_max:Number(document.getElementById('amtMax').value),
    review_at:Number(document.getElementById('reviewAt').value),
    deny_at:Number(document.getElementById('denyAt').value)
  };
  show(await (await fetch('/api/config',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})).json());
}
async function state(){ show(await (await fetch('/api/state')).json()); }
async function reset(){ show(await (await fetch('/api/reset',{method:'POST'})).json()); }
</script>
</body></html>
HTML;
}
