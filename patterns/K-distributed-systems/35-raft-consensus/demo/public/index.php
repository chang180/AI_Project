<?php
declare(strict_types=1);

/**
 * 共識演算法 Raft Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8035 -t public
 *
 * 路由：
 *   GET  /             測試頁（每節點 role/term/log/commitIndex；tick / 提交 command / kill / revive / reset）
 *   POST /api/tick     推進邏輯時鐘 { steps }，回傳發生的事件（選舉/複製/commit）
 *   POST /api/command  提交一個 command（只有 leader 接受） { command }
 *   POST /api/node     kill / revive 節點 { id, alive }
 *   GET  /api/state    取得目前叢集狀態
 *   POST /api/reset    重置叢集 { n }（n=3 或 5）
 */

require __DIR__ . '/../src/RaftCluster.php';

$cluster = new RaftCluster(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- POST /api/tick ----
if ($method === 'POST' && $path === '/api/tick') {
    $in = read_body();
    $steps = (int) ($in['steps'] ?? 1);
    exit(json_out($cluster->tick($steps)));
}

// ---- POST /api/command ----
if ($method === 'POST' && $path === '/api/command') {
    $in = read_body();
    $command = trim((string) ($in['command'] ?? ''));
    if ($command === '') {
        http_response_code(400);
        exit(json_out(['ok' => false, 'error' => 'command 不可為空']));
    }
    exit(json_out($cluster->submitCommand($command)));
}

// ---- POST /api/node：kill / revive ----
if ($method === 'POST' && $path === '/api/node') {
    $in = read_body();
    $id = trim((string) ($in['id'] ?? ''));
    $alive = (bool) ($in['alive'] ?? false);
    exit(json_out($cluster->setNodeAlive($id, $alive)));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out($cluster->getState()));
}

// ---- POST /api/reset ----
if ($method === 'POST' && $path === '/api/reset') {
    $in = read_body();
    $n = (int) ($in['n'] ?? 3);
    exit(json_out(['ok' => true, 'state' => $cluster->reset($n)]));
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
<title>共識演算法 Raft · Demo</title>
<style>
body{max-width:980px;margin:34px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#16181c;color:#e6e6e6;line-height:1.6}
h1{font-size:1.5rem}h2{font-size:1.1rem;margin-top:1.6em;border-bottom:1px solid #333;padding-bottom:.3em}
code{background:#222;padding:2px 6px;border-radius:4px;color:#7ee787}
.box{border-left:4px solid #58a6ff;background:#1b1d22;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
input,select,button{font-size:.92rem;padding:6px 8px;border-radius:6px;border:1px solid #444;background:#0f1115;color:#e6e6e6;margin:2px}
button{background:#1f6feb;border-color:#1f6feb;cursor:pointer}button:hover{background:#388bfd}
.row{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:.4em 0}
pre{background:#0f1115;border:1px solid #333;border-radius:8px;padding:12px;overflow:auto;font-size:.82rem;max-height:300px}
.nodes{display:flex;flex-wrap:wrap;gap:12px;margin:.6em 0}
.node{flex:1 1 280px;border:1px solid #333;border-radius:10px;padding:12px;background:#1b1d22}
.node h3{margin:.1em 0;font-size:1rem;display:flex;justify-content:space-between;align-items:center}
.badge{font-size:.72rem;padding:2px 8px;border-radius:10px;font-weight:700}
.leader{background:#2ea043;color:#fff}.follower{background:#30363d;color:#aab}.candidate{background:#bb8009;color:#fff}.DOWN{background:#8b1a1a;color:#fff}
.meta{font-size:.82rem;color:#aab}.meta b{color:#e6e6e6}
.logline{font-family:ui-monospace,monospace;font-size:.78rem;background:#0f1115;border:1px solid #2a2f37;border-radius:5px;padding:2px 6px;margin:2px 0;display:inline-block}
.committed{border-color:#2ea043;color:#7ee787}
.kv{font-size:.78rem;color:#9fb;margin-top:4px}
label{font-size:.85rem;color:#aab}
</style>
</head><body>
<h1>🗳️ 共識演算法 Raft · Demo</h1>
<p>單 process 內模擬 3~5 節點 + <b>手動 tick 驅動邏輯時鐘</b>。真實的 Raft 規則：
<code>leader election</code>（選舉逾時→投票限制）、<code>log replication</code>（AppendEntries 一致性檢查/回退）、
<code>commit 規則</code>（多數 + 當前 term）、<code>safety</code>（term 規則 + 選舉限制）。</p>

<div class="box"><span class="label">操作流程（建議照順序試）</span>
1. <b>reset</b> 重置 → 2. <b>tick 數次</b>看 follower 逾時 → candidate → 多數投票 → <b>選出 leader</b> →
3. <b>提交 2~3 個 command</b>（如 <code>x=1</code>、<code>cnt++</code>）看複製到多數並 commitIndex 前進 →
4. <b>kill 掉目前 leader</b> → 5. 再 <b>tick 數次</b>看存活多數重新選出新 leader、且已 commit 的 log 不丟。
</div>

<div class="row">
  重置為 <select id="r_n"><option value="3">3 節點</option><option value="5">5 節點</option></select>
  <button onclick="reset()">reset</button>
  &nbsp;|&nbsp;
  tick <input id="t_steps" value="3" size="3"> 次 <button onclick="tick()">⏱️ tick</button>
  &nbsp;|&nbsp;
  command <input id="c_cmd" value="x=1" size="10"> <button onclick="cmd()">📥 提交</button>
</div>

<h2>叢集狀態 <span id="clock" class="meta"></span></h2>
<div id="nodes" class="nodes"></div>

<h2>事件 / 輸出</h2>
<pre id="out">（操作結果顯示於此）</pre>

<p style="color:#888;font-size:.82rem;margin-top:2em">※ 誠實聲明：這是<b>單 process + 手動 tick 的確定性模擬</b>，沒有真實網路 RPC，也沒有真正的背景隨機計時器（逾時用邏輯時鐘確定性錯開）。但<b>選舉、投票限制、日誌複製、一致性檢查/回退、commit（多數+當前 term）、term 與選舉限制等安全性規則皆為真實正確的 Raft 邏輯</b>。狀態持久化於 <code>data/cluster.json</code>（flock 保護）。</p>

<script>
const out = document.getElementById('out');
function show(o){ out.textContent = JSON.stringify(o, null, 2); }
async function post(url, body){ const r = await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body||{})}); return r.json(); }
async function get(url){ const r = await fetch(url); return r.json(); }
function v(id){ return document.getElementById(id).value; }

function renderState(st){
  document.getElementById('clock').textContent =
    '邏輯時鐘 t=' + st.logicalClock + ' ｜ leader=' + (st.leader||'(無)') + ' ｜ 多數需 ' + st.majority + '/' + st.nodeCount;
  const box = document.getElementById('nodes');
  box.innerHTML = '';
  for (const n of st.nodes){
    const div = document.createElement('div');
    div.className = 'node';
    let logHtml = '';
    n.log.forEach((e, i) => {
      const idx = i + 1;
      const cls = idx <= n.commitIndex ? 'logline committed' : 'logline';
      logHtml += '<span class="'+cls+'" title="index '+idx+'">'+idx+'. '+e+'</span> ';
    });
    if (!logHtml) logHtml = '<span class="meta">（空 log）</span>';
    const kv = Object.keys(n.kv||{}).length ? JSON.stringify(n.kv) : '{}';
    const aliveBtn = n.alive
      ? '<button onclick="node(\\''+n.id+'\\',false)">kill</button>'
      : '<button onclick="node(\\''+n.id+'\\',true)">revive</button>';
    div.innerHTML =
      '<h3>'+n.id+' <span class="badge '+n.role+'">'+n.role+'</span></h3>'+
      '<div class="meta">term <b>'+n.term+'</b> ｜ votedFor <b>'+(n.votedFor||'-')+'</b>'+
      (n.electionTimer!==null?' ｜ 選舉倒數 <b>'+n.electionTimer+'</b>':'')+'</div>'+
      '<div class="meta">commitIndex <b>'+n.commitIndex+'</b> ｜ applied <b>'+n.lastApplied+'</b> ｜ logLen <b>'+n.logLen+'</b></div>'+
      '<div style="margin:6px 0">'+logHtml+'</div>'+
      '<div class="kv">狀態機 KV：'+kv+'</div>'+
      '<div style="margin-top:6px">'+aliveBtn+'</div>';
    box.appendChild(div);
  }
}

async function refresh(){ renderState(await get('/api/state')); }
async function reset(){ const r = await post('/api/reset',{n:+v('r_n')}); renderState(r.state); show(r); }
async function tick(){ const r = await post('/api/tick',{steps:+v('t_steps')}); renderState(r.state); show({steps:r.steps, events:r.events}); }
async function cmd(){ const r = await post('/api/command',{command:v('c_cmd')}); renderState(r.state); show(r); }
async function node(id, alive){ const r = await post('/api/node',{id:id, alive:alive}); renderState(r.state); show(r); }
refresh();
</script>
</body></html>
HTML;
}
