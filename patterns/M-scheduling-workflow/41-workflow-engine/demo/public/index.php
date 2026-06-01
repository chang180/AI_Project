<?php
declare(strict_types=1);

/**
 * 工作流引擎 (Temporal/Airflow) Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8041 -t public
 *
 * 路由：
 *   GET  /                 測試頁（載入範例 DAG、步進 tick 看 ready step 推進、
 *                          注入失敗看重試→saga 補償、審批 signal 放行、reset 後重放不重跑）
 *   POST /api/workflow     定義工作流 { steps:[...] }；或 { sample:true } 載入內建範例 DAG
 *   POST /api/run          tick：執行一個排程步（挑 ready step 推進）
 *   POST /api/signal       審批放行 { step }
 *   POST /api/fail         注入失敗 { step }（示範重試 + saga 補償）
 *   GET  /api/state        目前狀態（DAG、各 step 狀態、ready 清單、event log）
 *   POST /api/reset        清空 event log（保留定義）→ 示範「重放不重跑」
 */

require __DIR__ . '/../src/WorkflowEngine.php';

$engine = new WorkflowEngine(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- POST /api/workflow：定義工作流 ----
if ($method === 'POST' && $path === '/api/workflow') {
    $in = read_body();
    if (!empty($in['sample'])) {
        $engine->loadSample();
        exit(json_out(['ok' => true, 'loaded' => 'sample', 'state' => $engine->state()]));
    }
    $steps = $in['steps'] ?? null;
    if (!is_array($steps) || $steps === []) {
        http_response_code(400);
        exit(json_out(['error' => 'steps 必須是非空陣列，或傳 { "sample": true }']));
    }
    try {
        $engine->define($steps);
    } catch (Throwable $e) {
        http_response_code(400);
        exit(json_out(['error' => $e->getMessage()]));
    }
    exit(json_out(['ok' => true, 'state' => $engine->state()]));
}

// ---- POST /api/run：tick 一步 ----
if ($method === 'POST' && $path === '/api/run') {
    if (!$engine->hasDefinition()) {
        http_response_code(400);
        exit(json_out(['error' => '尚未定義工作流，請先載入範例 DAG（POST /api/workflow {sample:true}）']));
    }
    exit(json_out($engine->tick()));
}

// ---- POST /api/signal：審批放行 ----
if ($method === 'POST' && $path === '/api/signal') {
    $in = read_body();
    $step = trim((string) ($in['step'] ?? ''));
    if ($step === '') {
        http_response_code(400);
        exit(json_out(['error' => '需要 step']));
    }
    $res = $engine->signal($step);
    if (!($res['ok'] ?? false)) {
        http_response_code(409);
    }
    exit(json_out($res));
}

// ---- POST /api/fail：注入失敗 ----
if ($method === 'POST' && $path === '/api/fail') {
    $in = read_body();
    $step = trim((string) ($in['step'] ?? ''));
    if ($step === '') {
        http_response_code(400);
        exit(json_out(['error' => '需要 step']));
    }
    exit(json_out($engine->injectFail($step)));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out(['state' => $engine->state()]));
}

// ---- POST /api/reset ----
if ($method === 'POST' && $path === '/api/reset') {
    if (!$engine->hasDefinition()) {
        http_response_code(400);
        exit(json_out(['error' => '尚未定義工作流']));
    }
    $engine->resetRun();
    exit(json_out([
        'ok' => true,
        'note' => 'event log 已清空（定義保留）。接著 tick 會重新跑；若 event log 還在，replay 只讀 log 不重跑已完成 step。',
        'state' => $engine->state(),
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

function render_home(): void
{
    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>工作流引擎 (Temporal/Airflow) · Demo</title>
<style>
body{max-width:960px;margin:36px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#16181c;color:#e6e6e6;line-height:1.6}
h1{font-size:1.5rem}h2{font-size:1.05rem;margin-top:1.6em;border-bottom:1px solid #333;padding-bottom:.3em}
code{background:#222;padding:2px 6px;border-radius:4px;color:#7ee787}
.box{border-left:4px solid #58a6ff;background:#1b1d22;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
table{width:100%;border-collapse:collapse;margin:.6em 0}td,th{border:1px solid #333;padding:7px 9px;text-align:left;font-size:.9rem}
th{background:#20242b}
button{font-size:.9rem;padding:6px 10px;border-radius:6px;border:1px solid #1f6feb;background:#1f6feb;color:#fff;cursor:pointer;margin:2px}
button:hover{background:#388bfd}
button.alt{background:#30363d;border-color:#444}button.alt:hover{background:#3d444d}
input{font-size:.9rem;padding:6px 8px;border-radius:6px;border:1px solid #444;background:#0f1115;color:#e6e6e6;margin:2px}
.row{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:.4em 0}
pre{background:#0f1115;border:1px solid #333;border-radius:8px;padding:12px;overflow:auto;font-size:.82rem;max-height:300px}
.s-DONE{color:#7ee787}.s-PENDING{color:#8b949e}.s-FAILED{color:#ff7b72}.s-BLOCKED{color:#d29922}.s-COMPENSATED{color:#bc8cff}
.pill{display:inline-block;padding:1px 7px;border-radius:10px;font-size:.78rem;background:#222}
.dep{color:#8b949e;font-size:.82rem}
</style>
</head><body>
<h1>🛠️ 工作流引擎 (Temporal/Airflow) · Demo</h1>
<p>真實邏輯：<strong>DAG 排程</strong>（deps 全完成才 ready）、<strong>durable execution / 事件溯源</strong>（狀態 = 重放 event log）、<strong>重試 + 指數退避</strong>、<strong>saga 反向補償</strong>、<strong>人工審批等 signal</strong>、<strong>重放不重跑</strong>。</p>

<h2>① 載入範例 DAG</h2>
<div class="row">
  <button onclick="loadSample()">載入訂單履約範例 DAG</button>
  <span class="dep">reserve, charge_prep → approve(審批) → pay → ship → notify</span>
</div>

<h2>② 步進執行（每按一次 tick 推進一步）</h2>
<div class="row">
  <button onclick="tick()">▶ tick 一步</button>
  <button class="alt" onclick="autoRun()">⏩ 連跑到停（最多 30 步）</button>
  <button class="alt" onclick="refresh()">重新整理狀態</button>
</div>

<h2>③ 注入失敗（看重試 → saga 補償）</h2>
<div class="row">
  step <input id="f_step" value="ship" size="10">
  <button onclick="injectFail()">注入失敗</button>
  <span class="dep">注入後 tick 到該 step：會重試到上限再 FAILED，然後反向補償已完成 step</span>
</div>

<h2>④ 審批放行（signal）</h2>
<div class="row">
  step <input id="sg_step" value="approve" size="10">
  <button onclick="signal()">送 signal 放行</button>
  <span class="dep">tick 到審批節點會 BLOCKED，送 signal 後才繼續</span>
</div>

<h2>⑤ 重放展示（reset 後不重跑已完成 step）</h2>
<div class="row">
  <button class="alt" onclick="reset()">reset run（清 event log，保留定義）</button>
  <span class="dep">state 中 <code>replayedNoRerun</code> = 重放 log 直接拿結果、未重跑的 step 數</span>
</div>

<h2>DAG 狀態</h2>
<div id="dag"></div>

<h2>輸出（含 event log）</h2>
<pre id="out">（先「載入範例 DAG」，再按 tick 步進）</pre>

<p style="color:#888;font-size:.82rem;margin-top:2em">※ 單機 + 手動步進，無分散式叢集/多 worker/task queue；但 DAG 排程、事件溯源重放（含崩潰可續跑、重放不重跑）、重試退避、saga 補償、審批等 signal 皆為真實可執行。</p>

<script>
const out = document.getElementById('out');
const dag = document.getElementById('dag');
function show(o){ out.textContent = JSON.stringify(o, null, 2); if(o.state) renderDag(o.state); }
async function post(url, body){ const r = await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body||{})}); return r.json(); }
async function get(url){ const r = await fetch(url); return r.json(); }

function renderDag(st){
  if(!st || !st.steps){ dag.innerHTML=''; return; }
  let rows = st.steps.map(s => {
    const deps = (s.deps && s.deps.length) ? s.deps.join(', ') : '—';
    const isReady = (st.ready||[]).includes(s.id);
    return `<tr>
      <td><code>\${s.id}</code>\${isReady?' <span class="pill">ready</span>':''}</td>
      <td>\${s.name}</td>
      <td>\${s.type}</td>
      <td class="dep">\${deps}</td>
      <td class="s-\${s.status}">\${s.status}</td>
      <td>\${s.attempts}</td>
    </tr>`;
  }).join('');
  dag.innerHTML = `<p>流程狀態：<b class="s-\${st.terminal?(st.status==='COMPLETED'?'DONE':'FAILED'):'PENDING'}">\${st.status}\${st.terminal?'（終止）':''}</b>　事件數：\${st.eventCount}　重放不重跑：\${st.replayedNoRerun}</p>
    <table><tr><th>step</th><th>名稱</th><th>型別</th><th>相依 deps</th><th>狀態</th><th>嘗試</th></tr>\${rows}</table>`;
}

async function loadSample(){ show(await post('/api/workflow', {sample:true})); }
async function tick(){ show(await post('/api/run', {})); }
async function injectFail(){ show(await post('/api/fail', {step:v('f_step')})); }
async function signal(){ show(await post('/api/signal', {step:v('sg_step')})); }
async function reset(){ show(await post('/api/reset', {})); }
async function refresh(){ show(await get('/api/state')); }
async function autoRun(){
  let last;
  for(let i=0;i<30;i++){
    last = await post('/api/run', {});
    if(last.state && (last.state.terminal || (last.state.ready||[]).length===0)) break;
    // 審批中也停下
    if(last.message && last.message.indexOf('等待審批')>=0) break;
  }
  show(last);
}
function v(id){ return document.getElementById(id).value; }
</script>
</body></html>
HTML;
}
