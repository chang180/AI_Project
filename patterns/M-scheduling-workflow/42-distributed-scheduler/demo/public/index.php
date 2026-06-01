<?php
declare(strict_types=1);

/**
 * 分散式排程器 / Cron Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8042 -t public
 *
 * 路由：
 *   GET  /                  測試頁（加 job、tick 推進、多 worker 認領、模擬當掉、分片）
 *   POST /api/job           新增排程  { jobId, interval, misfirePolicy }
 *   POST /api/tick          推進邏輯時鐘  { steps } → 回傳本次到點的 fire
 *   POST /api/worker        worker 上線/下線  { workerId, online }
 *   POST /api/claim         worker 認領一個觸發  { workerId, fireId? }
 *   POST /api/complete      worker 完成執行  { workerId, fireId, fenceToken }
 *   GET  /api/state         目前完整狀態
 *   POST /api/reset         清空重來
 */

require __DIR__ . '/../src/Scheduler.php';

$sched = new Scheduler(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/job ----
if ($method === 'POST' && $path === '/api/job') {
    $in = body_input();
    $jobId = trim((string)($in['jobId'] ?? ''));
    $interval = (int)($in['interval'] ?? 0);
    $policy = (string)($in['misfirePolicy'] ?? 'fire-once');
    if ($jobId === '' || $interval < 1) {
        http_response_code(400);
        exit(json_out(['error' => 'jobId 必填、interval 須 >= 1']));
    }
    exit(json_out(['ok' => true, 'job' => $sched->addJob($jobId, $interval, $policy)]));
}

// ---- POST /api/tick ----
if ($method === 'POST' && $path === '/api/tick') {
    $in = body_input();
    $steps = max(1, (int)($in['steps'] ?? 1));
    exit(json_out(['ok' => true] + $sched->tick($steps)));
}

// ---- POST /api/worker ----
if ($method === 'POST' && $path === '/api/worker') {
    $in = body_input();
    $workerId = trim((string)($in['workerId'] ?? ''));
    $online = filter_var($in['online'] ?? true, FILTER_VALIDATE_BOOLEAN);
    if ($workerId === '') {
        http_response_code(400);
        exit(json_out(['error' => 'workerId 必填']));
    }
    exit(json_out(['ok' => true, 'worker' => $sched->setWorker($workerId, $online)]));
}

// ---- POST /api/claim ----
if ($method === 'POST' && $path === '/api/claim') {
    $in = body_input();
    $workerId = trim((string)($in['workerId'] ?? ''));
    $fireId = isset($in['fireId']) && $in['fireId'] !== '' ? (string)$in['fireId'] : null;
    if ($workerId === '') {
        http_response_code(400);
        exit(json_out(['error' => 'workerId 必填']));
    }
    $res = $sched->claim($workerId, $fireId);
    exit(json_out($res));
}

// ---- POST /api/complete ----
if ($method === 'POST' && $path === '/api/complete') {
    $in = body_input();
    $workerId = trim((string)($in['workerId'] ?? ''));
    $fireId = trim((string)($in['fireId'] ?? ''));
    $token = (int)($in['fenceToken'] ?? 0);
    if ($workerId === '' || $fireId === '') {
        http_response_code(400);
        exit(json_out(['error' => 'workerId、fireId 必填']));
    }
    exit(json_out($sched->complete($workerId, $fireId, $token)));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out($sched->state()));
}

// ---- POST /api/reset ----
if ($method === 'POST' && $path === '/api/reset') {
    $sched->reset();
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
function body_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
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
<title>分散式排程器 / Cron · Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>
body{max-width:960px;margin:32px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
code{background:#222;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse;font-size:.9rem}td,th{border:1px solid #444;padding:6px 8px;text-align:left}
.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:.4em 0}
button{cursor:pointer}
.pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:.78rem}
.s-pending{background:#553;color:#fe7}.s-claimed{background:#345;color:#7cf}
.s-done{background:#252;color:#7e9}.s-missed-skip{background:#522;color:#f99}
.muted{color:#888;font-size:.85rem}
#log{background:#111;border:1px solid #333;border-radius:8px;padding:10px;height:180px;overflow:auto;font-family:ui-monospace,monospace;font-size:.8rem;white-space:pre-wrap}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:760px){.cols{grid-template-columns:1fr}}
h3{margin:.6em 0 .2em}
</style></head><body>
<h1>⏱️ 分散式排程器 / Cron · Demo</h1>
<p>用「手動 tick 推進邏輯時鐘」做確定性模擬。加 job → tick 到點觸發 → 多 worker 搶
<b>claim</b>（只一個成功）→ complete；模擬 worker 當掉看 <b>misfire 補跑</b> 與 <b>租約接管</b>。</p>

<div class="box"><span class="label">邏輯時鐘 now = <span id="now">0</span>　線上 worker：<span id="ow">—</span></span>
<span class="muted">claim 用 fencing token + 租約（5 tick）；租約到期未 complete → 視為當掉，可被接管。</span></div>

<div class="cols">
<div>
  <h3>① 新增排程 job</h3>
  <div class="row">
    <input id="jobId" placeholder="job 名稱，如 send-report" style="flex:1">
  </div>
  <div class="row">
    每 <input id="interval" type="number" value="3" min="1" style="width:64px"> tick 觸發
    misfire
    <select id="policy">
      <option value="fire-once">fire-once（補跑一次）</option>
      <option value="skip">skip（跳過）</option>
      <option value="fire-all">fire-all（全補）</option>
    </select>
    <button onclick="addJob()">加 job</button>
  </div>

  <h3>② 推進時鐘（觸發掃描）</h3>
  <div class="row">
    <button onclick="tick(1)">tick +1</button>
    <button onclick="tick(3)">tick +3</button>
    <button onclick="tick(10)">tick +10</button>
  </div>

  <h3>③ Worker 上線 / 下線</h3>
  <div class="row">
    <input id="workerId" placeholder="worker id，如 w1" value="w1" style="width:120px">
    <button onclick="setWorker(true)">上線</button>
    <button onclick="setWorker(false)">下線（模擬當掉）</button>
  </div>
  <p class="muted">多開幾個 worker（w1/w2/w3）觀察分片指派；把認領中的 worker 下線並 tick 過租約，再讓別人接管。</p>
</div>

<div>
  <h3>④ 認領 / 完成（防重複執行）</h3>
  <div class="row">
    worker <input id="cWorker" placeholder="w1" value="w1" style="width:80px">
    <button onclick="claim()">認領一個觸發</button>
  </div>
  <p class="muted">同一個 fire 連點兩次（或不同 worker 搶）→ 只有一個成功，其餘顯示「已被認領／去重生效」。</p>
  <div class="row">
    <button onclick="reset()">🗑️ 全部重置</button>
    <button onclick="loadState()">↻ 重新整理</button>
  </div>
  <h3>事件記錄</h3>
  <div id="log"></div>
</div>
</div>

<h3>Jobs</h3>
<table id="jobs"><thead><tr><th>job</th><th>每N tick</th><th>nextRun</th><th>misfire</th><th>分片指派</th></tr></thead><tbody></tbody></table>

<h3>Fires（每次到點觸發一筆；claim 後只一個能執行）</h3>
<table id="fires"><thead><tr><th>fireId</th><th>job</th><th>scheduledAt</th><th>指派</th><th>狀態</th><th>認領者 / token</th><th>操作</th></tr></thead><tbody></tbody></table>

<p class="muted">※ 誠實聲明：本 demo 為單機 + 手動 tick 模擬「多 worker 常駐排程」。
到點觸發、防重複 claim（fencing + 租約）、misfire 補跑、分片指派皆為真實可執行邏輯；
真實系統改為多進程常駐、共用協調儲存（DB/etcd）、心跳偵測當掉、leader 選舉避免重複掃描。</p>

<script>
const $ = s => document.querySelector(s);
function log(m){ const el=$('#log'); el.textContent += m+"\n"; el.scrollTop=el.scrollHeight; }
async function api(path, body){
  const r = await fetch(path, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body||{})});
  return r.json();
}
async function addJob(){
  const jobId=$('#jobId').value.trim(); if(!jobId){log('⚠️ 請填 job 名稱');return;}
  const r=await api('/api/job',{jobId, interval:+$('#interval').value, misfirePolicy:$('#policy').value});
  log(r.ok?`➕ job ${jobId} 加入，nextRun=${r.job.nextRun}`:'❌ '+(r.error||''));
  loadState();
}
async function tick(n){
  const r=await api('/api/tick',{steps:n});
  log(`⏩ now→${r.now}，本次觸發 ${r.fired.length} 個：`+r.fired.map(f=>f.fireId+'('+f.jobId+(f.note?' '+f.note:'')+')').join(', '));
  loadState();
}
async function setWorker(online){
  const id=$('#workerId').value.trim(); if(!id){log('⚠️ 請填 worker id');return;}
  const r=await api('/api/worker',{workerId:id, online});
  log(`🖥️ worker ${id} ${online?'上線':'下線'}`);
  loadState();
}
async function claim(fireId){
  const w=$('#cWorker').value.trim(); if(!w){log('⚠️ 請填 worker');return;}
  const r=await api('/api/claim',{workerId:w, fireId});
  if(r.ok){
    log(`🔒 ${w} 認領 ${r.fire.fireId} 成功（token=${r.fire.fenceToken}，租約到 ${r.fire.leaseUntil}）`);
    // 認領成功 → 立刻 complete（教學：示範 fencing 驗證後去重）
    const c=await api('/api/complete',{workerId:w, fireId:r.fire.fireId, fenceToken:r.fire.fenceToken});
    log(c.ok?`✅ ${w} 完成 ${r.fire.fireId}（去重：此觸發只算一次）`:`↩️ complete: ${c.reason}`);
  } else {
    log(`🚫 認領失敗：${r.reason}`);
  }
  loadState();
}
async function reset(){ await api('/api/reset',{}); log('— 已重置 —'); loadState(); }

function pill(s){ return `<span class="pill s-${s}">${s}</span>`; }
async function loadState(){
  const st=await (await fetch('/api/state')).json();
  $('#now').textContent=st.now;
  $('#ow').textContent=(st.onlineWorkers&&st.onlineWorkers.length)?st.onlineWorkers.join(', '):'（無）';
  const jb=$('#jobs').querySelector('tbody'); jb.innerHTML='';
  Object.values(st.jobs||{}).forEach(j=>{
    jb.innerHTML+=`<tr><td><code>${j.id}</code></td><td>${j.interval}</td><td>${j.nextRun}</td><td>${j.misfirePolicy}</td><td>${j.assignedTo||'<span class="muted">無線上 worker</span>'}</td></tr>`;
  });
  const fb=$('#fires').querySelector('tbody'); fb.innerHTML='';
  (st.fires||[]).slice().reverse().forEach(f=>{
    const claimable=(f.status==='pending'||(f.status==='claimed'&&f.leaseUntil<st.now));
    const op=claimable?`<button onclick="claim('${f.fireId}')">搶認領</button>`:'<span class="muted">—</span>';
    const who=f.claimedBy?`${f.claimedBy} / t${f.fenceToken}`:'<span class="muted">—</span>';
    fb.innerHTML+=`<tr><td><code>${f.fireId}</code></td><td>${f.jobId}</td><td>${f.scheduledAt}</td><td>${f.assignedWorker||'—'}</td><td>${pill(f.status)}${f.note?'<br><span class="muted">'+f.note+'</span>':''}</td><td>${who}</td><td>${op}</td></tr>`;
  });
}
loadState();
</script>
</body></html>
HTML;
}
