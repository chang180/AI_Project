<?php
declare(strict_types=1);

/**
 * LLM 推論 API Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8014 -t public
 *
 * 聚焦考點：continuous batching（連續批次）如何動態填補 GPU 槽位。
 * 排程器狀態存於 data/scheduler.json（執行期自建、已 gitignore）。
 *
 * 路由：
 *   GET  /                    深色測試頁（提交請求 / 推進 / 重置 / 利用率對比）
 *   POST /api/submit          提交一個生成請求 { tenant, priority, prompt, max_tokens }
 *   POST /api/step            推進排程器一個 step（回傳本 step 快照）
 *   POST /api/run             一路推進到全部完成
 *   POST /api/reset           重置排程器（可帶 max_batch）
 *   GET  /api/state           目前統計 + 歷史 step 快照
 */

require __DIR__ . '/../src/InferenceModel.php';
require __DIR__ . '/../src/Request.php';
require __DIR__ . '/../src/BatchScheduler.php';

$dataDir  = __DIR__ . '/../data';
$stateFile = $dataDir . '/scheduler.json';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

/**
 * 簡化版的狀態持久化：因 PHP 內建伺服器是每請求一個 process，
 * 排程器物件無法跨請求常駐，故把「請求清單 + 設定」存 JSON，
 * 每次 API 呼叫時重建排程器並重放到目前的 step。
 */
function load_state(string $file): array
{
    if (!file_exists($file)) {
        return ['maxBatch' => 4, 'submissions' => [], 'stepsDone' => 0, 'seq' => 0];
    }
    $s = json_decode((string) file_get_contents($file), true);
    return is_array($s) ? $s : ['maxBatch' => 4, 'submissions' => [], 'stepsDone' => 0, 'seq' => 0];
}

function save_state(string $file, array $s): void
{
    file_put_contents(
        $file,
        json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/**
 * 依目前狀態重建排程器：把所有已提交請求放進去，再推進 stepsDone 個 step。
 * 這讓「提交 → 推進 → 動態填補」在無常駐 process 的 demo 中仍可重現。
 */
function rebuild(array $s): BatchScheduler
{
    $sched = new BatchScheduler((int) $s['maxBatch'], new InferenceModel());
    // 依提交順序送入；submit 內部會依優先級排序佇列
    foreach ($s['submissions'] as $sub) {
        $sched->submit(
            (string) $sub['tenant'],
            (int) $sub['priority'],
            (string) $sub['prompt'],
            (int) $sub['max_tokens']
        );
    }
    for ($i = 0; $i < (int) $s['stepsDone']; $i++) {
        if ($sched->hasWork()) {
            $sched->step();
        }
    }
    return $sched;
}

// ---- POST /api/submit ----
if ($method === 'POST' && $path === '/api/submit') {
    $in = read_input();
    $s = load_state($stateFile);
    $s['submissions'][] = [
        'tenant'     => trim((string) ($in['tenant'] ?? 'free')) ?: 'free',
        'priority'   => max(0, min(2, (int) ($in['priority'] ?? 1))),
        'prompt'     => trim((string) ($in['prompt'] ?? '')) ?: ('prompt-' . count($s['submissions'])),
        'max_tokens' => max(1, min(64, (int) ($in['max_tokens'] ?? 12))),
    ];
    save_state($stateFile, $s);
    exit(json_out(['ok' => true, 'submitted' => count($s['submissions'])]));
}

// ---- POST /api/step ----
if ($method === 'POST' && $path === '/api/step') {
    $s = load_state($stateFile);
    $sched = rebuild($s);
    if (!$sched->hasWork()) {
        exit(json_out(['ok' => false, 'msg' => '已無工作可推進', 'stats' => $sched->stats()]));
    }
    $snap = $sched->step();
    $s['stepsDone']++;
    save_state($stateFile, $s);
    exit(json_out(['ok' => true, 'snapshot' => $snap, 'stats' => $sched->stats()]));
}

// ---- POST /api/run ----
if ($method === 'POST' && $path === '/api/run') {
    $s = load_state($stateFile);
    $sched = rebuild($s);
    $sched->runToCompletion();
    $s['stepsDone'] = $sched->stats()['steps'];
    save_state($stateFile, $s);
    exit(json_out(['ok' => true, 'history' => $sched->history(), 'stats' => $sched->stats()]));
}

// ---- POST /api/reset ----
if ($method === 'POST' && $path === '/api/reset') {
    $in = read_input();
    $maxBatch = max(1, min(8, (int) ($in['max_batch'] ?? 4)));
    save_state($stateFile, ['maxBatch' => $maxBatch, 'submissions' => [], 'stepsDone' => 0, 'seq' => 0]);
    exit(json_out(['ok' => true, 'maxBatch' => $maxBatch]));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    $s = load_state($stateFile);
    $sched = rebuild($s);
    exit(json_out([
        'ok'       => true,
        'maxBatch' => $s['maxBatch'],
        'pending'  => count($s['submissions']),
        'stats'    => $sched->stats(),
        'history'  => $sched->history(),
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
function read_input(): array
{
    // 優先解析 JSON body（前端 fetch 與 curl -d '{...}' 皆送 JSON）
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    // 後援：表單編碼
    if (!empty($_POST)) {
        return $_POST;
    }
    return [];
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function render_home(): void
{
    echo <<<'HTML'
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LLM 推論 API · Demo（continuous batching）</title>
<style>
:root{--bg:#0f1419;--card:#1e2630;--soft:#1a2027;--border:#2a3540;--text:#e6edf3;
--dim:#9aa7b3;--accent:#58a6ff;--green:#7ee787;--warn:#f0883e;--code:#161b22;}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);line-height:1.6;
font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif}
.wrap{max-width:1000px;margin:0 auto;padding:28px 20px 80px}
h1{font-size:1.6rem;margin:.2em 0}
h2{font-size:1.15rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin-top:1.6em}
.dim{color:var(--dim)}
.box{border-left:4px solid var(--green);background:var(--soft);padding:10px 14px;border-radius:0 8px 8px 0;margin:1em 0}
.box.warn{border-color:var(--warn)}
.box .label{font-weight:700;display:block;margin-bottom:.2em}
label{display:inline-block;color:var(--dim);font-size:.85rem;margin:4px 8px 4px 0}
input,select{background:var(--code);color:var(--text);border:1px solid var(--border);
border-radius:6px;padding:6px 8px;font-size:.9rem}
button{background:var(--accent);color:#0b1117;border:none;border-radius:6px;
padding:8px 14px;font-weight:700;cursor:pointer;margin:4px 6px 4px 0}
button.ghost{background:var(--soft);color:var(--text);border:1px solid var(--border)}
button:hover{filter:brightness(1.1)}
code{background:var(--code);padding:2px 6px;border-radius:5px;color:var(--green);font-size:.85em}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin:14px 0}
.slots{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}
.slot{flex:1 1 120px;min-width:120px;border:1px solid var(--border);border-radius:8px;
padding:8px 10px;background:var(--soft);font-size:.82rem}
.slot.empty{opacity:.45;border-style:dashed}
.slot.fresh{border-color:var(--green);box-shadow:0 0 0 1px var(--green) inset}
.slot.done{border-color:var(--warn)}
.bar{height:8px;background:var(--code);border-radius:999px;overflow:hidden;margin-top:4px}
.bar>span{display:block;height:100%;background:linear-gradient(90deg,var(--accent),var(--green))}
table{width:100%;border-collapse:collapse;font-size:.85rem;margin:8px 0}
th,td{border:1px solid var(--border);padding:6px 8px;text-align:left}
th{background:var(--soft);color:var(--accent)}
.pill{display:inline-block;font-size:.72rem;padding:1px 8px;border-radius:999px;border:1px solid var(--border);color:var(--dim)}
.util{display:flex;gap:16px;flex-wrap:wrap}
.util .metric{flex:1 1 200px;background:var(--soft);border:1px solid var(--border);border-radius:10px;padding:12px 14px}
.util .big{font-size:1.6rem;font-weight:800}
.util .c{color:var(--green)} .util .s{color:var(--warn)}
</style></head><body><div class="wrap">
<h1>🧠 LLM 推論 API · Demo</h1>
<p class="dim">聚焦真正考點：<b>continuous batching</b> —— 完成的請求<b>當場讓出 GPU 槽位</b>，
排程器立刻從佇列拉新請求<b>填補</b>，而非等整批做完。沒有真 GPU/模型，排程語意為真。</p>

<div class="card">
  <h2 style="margin-top:0">① 設定批次大小（GPU 槽位數）</h2>
  <label>最大批次（1～8，受 KV cache 顯存約束）</label>
  <input id="maxBatch" type="number" value="4" min="1" max="8" style="width:70px">
  <button class="ghost" onclick="reset()">重置排程器</button>
  <span id="cfg" class="dim"></span>
</div>

<div class="card">
  <h2 style="margin-top:0">② 提交生成請求（不同長度 / 優先級）</h2>
  <label>tenant</label><input id="tenant" value="acme" style="width:90px">
  <label>優先級</label>
  <select id="priority"><option value="0">0 高(付費)</option><option value="1" selected>1 一般</option><option value="2">2 batch</option></select>
  <label>prompt</label><input id="prompt" placeholder="任意文字決定輸出長度" style="width:220px">
  <label>max_tokens</label><input id="maxtok" type="number" value="12" min="1" max="64" style="width:70px">
  <button onclick="submitReq()">提交請求</button>
  <button class="ghost" onclick="seed()">快速塞 6 個範例請求</button>
  <span id="subMsg" class="dim"></span>
</div>

<div class="card">
  <h2 style="margin-top:0">③ 推進排程器</h2>
  <button onclick="step()">推進一個 step ▶</button>
  <button class="ghost" onclick="runAll()">一路跑到完成 ⏩</button>
  <p class="dim" style="font-size:.82rem">每 step：先填補空槽（拉佇列新請求做 prefill）→ 再為批次每個請求生 1 個 token → 完成者讓出槽位。</p>
  <div id="curStep"></div>
  <h3 style="font-size:1rem">GPU 槽位（本 step 狀態）</h3>
  <div id="slots" class="slots"></div>
</div>

<div class="card">
  <h2 style="margin-top:0">④ GPU 槽利用率：連續批次 vs 靜態批次</h2>
  <div class="util">
    <div class="metric"><div class="dim">連續批次（本排程器實測）</div><div id="contUtil" class="big c">—</div></div>
    <div class="metric"><div class="dim">靜態批次（理論對照，等最慢請求）</div><div id="statUtil" class="big s">—</div></div>
    <div class="metric"><div class="dim">已完成 / 佇列剩餘 / 已用 step</div><div id="counts" class="big">—</div></div>
  </div>
  <div class="box warn"><span class="label">解讀</span>連續批次利用率明顯高於靜態批次，差距就是「靜態批次空等最慢請求而浪費的 GPU 槽位」。請求長度差異越大，差距越明顯。</div>
</div>

<div class="card">
  <h2 style="margin-top:0">⑤ 每 step 歷史</h2>
  <div id="history"><span class="dim">尚無紀錄</span></div>
</div>

<script>
const api = (p, body) => fetch(p, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body||{})}).then(r=>r.json());
const getState = () => fetch('/api/state').then(r=>r.json());

function priClass(p){return p==0?'#7ee787':(p==1?'#58a6ff':'#9aa7b3');}

async function reset(){
  const mb = parseInt(document.getElementById('maxBatch').value)||4;
  const r = await api('/api/reset',{max_batch:mb});
  document.getElementById('cfg').textContent = '已重置，批次大小='+r.maxBatch;
  render();
}
async function submitReq(){
  const r = await api('/api/submit',{
    tenant:document.getElementById('tenant').value,
    priority:parseInt(document.getElementById('priority').value),
    prompt:document.getElementById('prompt').value,
    max_tokens:parseInt(document.getElementById('maxtok').value)
  });
  document.getElementById('subMsg').textContent = '已提交 '+r.submitted+' 個請求';
  render();
}
async function seed(){
  const ex = [
    {tenant:'acme',priority:0,prompt:'short-a',max_tokens:64},
    {tenant:'acme',priority:1,prompt:'medium-question-here',max_tokens:64},
    {tenant:'free',priority:1,prompt:'tell me a long story please',max_tokens:64},
    {tenant:'free',priority:2,prompt:'batch-job-x',max_tokens:64},
    {tenant:'acme',priority:1,prompt:'q2',max_tokens:64},
    {tenant:'free',priority:1,prompt:'another-prompt-zzz',max_tokens:64},
  ];
  for(const e of ex){ await api('/api/submit',e); }
  document.getElementById('subMsg').textContent='已塞入 6 個範例請求（長度各異）';
  render();
}
async function step(){
  const r = await api('/api/step',{});
  if(!r.ok){ document.getElementById('curStep').innerHTML='<span class="dim">'+(r.msg||'無工作')+'</span>'; }
  render();
}
async function runAll(){ await api('/api/run',{}); render(); }

function renderSlots(snap){
  const el = document.getElementById('slots'); el.innerHTML='';
  if(!snap){ el.innerHTML='<span class="dim">尚未推進</span>'; return; }
  const slots = snap.slots;
  Object.keys(slots).forEach(i=>{
    const s = slots[i]; const d = document.createElement('div');
    if(s.empty){ d.className='slot empty'; d.innerHTML='槽 '+i+'<br><span class="dim">空</span>'; }
    else{
      const fresh = (snap.filled||[]).includes(s.id);
      const done = s.state==='DONE';
      d.className='slot'+(fresh?' fresh':'')+(done?' done':'');
      const pct = Math.round(s.generated/s.planned*100);
      d.innerHTML='槽 '+i+' <span class="pill" style="color:'+priClass(s.priority)+'">P'+s.priority+'</span><br>'
        +'<b>'+s.id+'</b> <span class="dim">('+s.tenant+')</span><br>'
        +'<span class="dim">'+s.state+'</span> '+s.generated+'/'+s.planned+' tok'
        +(fresh?' <span style="color:#7ee787">←新填補</span>':'')
        +'<div class="bar"><span style="width:'+pct+'%"></span></div>';
    }
    el.appendChild(d);
  });
}

async function render(){
  const st = await getState();
  document.getElementById('cfg').textContent='批次大小='+st.maxBatch+'，待處理提交='+st.pending;
  const hist = st.history||[];
  const last = hist[hist.length-1];
  renderSlots(last);
  if(last){
    document.getElementById('curStep').innerHTML='目前 step <b>'+last.step+'</b>　本 step 生成 '
      +Object.keys(last.tokens).length+' 個 token　佇列剩 '+last.queueLen
      +(last.filled.length?'　<span style="color:#7ee787">填補槽位：'+last.filled.join(', ')+'</span>':'');
  } else { document.getElementById('curStep').innerHTML='<span class="dim">尚未推進</span>'; }

  document.getElementById('contUtil').textContent = st.stats.continuousUtil+'%';
  document.getElementById('statUtil').textContent = st.stats.staticBaselineUtil+'%';
  document.getElementById('counts').textContent = st.stats.doneCount+' / '+st.stats.queueLen+' / '+st.stats.steps;

  if(hist.length){
    let rows='<table><tr><th>step</th><th>填補</th><th>生成 token 數</th><th>佇列剩</th><th>已完成</th><th>本 step 利用率</th></tr>';
    hist.forEach(h=>{ rows+='<tr><td>'+h.step+'</td><td>'+(h.filled.join(', ')||'-')
      +'</td><td>'+Object.keys(h.tokens).length+'/'+h.capacity+'</td><td>'+h.queueLen
      +'</td><td>'+h.doneCount+'</td><td>'+h.utilisation+'%</td></tr>'; });
    rows+='</table>';
    document.getElementById('history').innerHTML=rows;
  } else { document.getElementById('history').innerHTML='<span class="dim">尚無紀錄</span>'; }
}
render();
</script>
</div></body></html>
HTML;
}
