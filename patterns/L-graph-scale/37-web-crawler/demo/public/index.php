<?php
declare(strict_types=1);

/**
 * 網頁爬蟲 Web Crawler Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8037 -t public
 *
 * 路由：
 *   GET  /             測試頁（載入假網路、單步/多步爬、看 frontier/visited/bloom/politeness/shard）
 *   POST /api/seed     載入範例假網路並設定種子  { seeds?: string[] }
 *   POST /api/crawl    步進 N 步  { steps }
 *   GET  /api/state    取得目前爬取狀態
 *   POST /api/reset    重置爬取狀態
 */

require __DIR__ . '/../src/Crawler.php';

$crawler = new Crawler(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- POST /api/seed ----
if ($method === 'POST' && $path === '/api/seed') {
    $in = read_body();
    $seeds = $in['seeds'] ?? [];
    if (is_string($seeds)) {
        $seeds = array_filter(array_map('trim', explode("\n", $seeds)));
    }
    $seeds = is_array($seeds) ? array_values($seeds) : [];
    exit(json_out(['ok' => true, 'state' => $crawler->seed($seeds)]));
}

// ---- POST /api/crawl ----
if ($method === 'POST' && $path === '/api/crawl') {
    $in = read_body();
    $steps = (int) ($in['steps'] ?? 1);
    exit(json_out($crawler->crawl($steps)));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out($crawler->getState()));
}

// ---- POST /api/reset ----
if ($method === 'POST' && $path === '/api/reset') {
    exit(json_out(['ok' => true, 'state' => $crawler->reset()]));
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
<title>網頁爬蟲 Web Crawler · Demo</title>
<style>
body{max-width:1000px;margin:34px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#16181c;color:#e6e6e6;line-height:1.6}
h1{font-size:1.5rem}h2{font-size:1.1rem;margin-top:1.6em;border-bottom:1px solid #333;padding-bottom:.3em}
code{background:#222;padding:2px 6px;border-radius:4px;color:#7ee787}
.box{border-left:4px solid #58a6ff;background:#1b1d22;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
input,select,button,textarea{font-size:.92rem;padding:6px 8px;border-radius:6px;border:1px solid #444;background:#0f1115;color:#e6e6e6;margin:2px}
button{background:#1f6feb;border-color:#1f6feb;cursor:pointer}button:hover{background:#388bfd}
.row{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:.4em 0}
pre{background:#0f1115;border:1px solid #333;border-radius:8px;padding:12px;overflow:auto;font-size:.8rem;max-height:320px}
.cols{display:flex;flex-wrap:wrap;gap:14px}
.col{flex:1 1 300px;border:1px solid #333;border-radius:10px;padding:12px;background:#1b1d22}
.col h3{margin:.1em 0 .4em;font-size:1rem}
.shard{border:1px solid #2a2f37;border-radius:8px;padding:8px;margin:6px 0;background:#0f1115}
.shard h4{margin:0 0 4px;font-size:.85rem;color:#9fb}
.u{font-family:ui-monospace,monospace;font-size:.76rem;padding:2px 6px;border-radius:5px;background:#1b1d22;border:1px solid #2a2f37;margin:2px 0;display:block}
.deferred{border-color:#bb8009;color:#f0c674}
.d0{color:#7ee787}.d1{color:#9cd}.d2{color:#c9a}
.stat{display:inline-block;border:1px solid #2a2f37;border-radius:8px;padding:6px 10px;margin:3px;font-size:.82rem;background:#0f1115}
.stat b{color:#7ee787}
table{width:100%;border-collapse:collapse;font-size:.8rem}td,th{border:1px solid #2a2f37;padding:4px 6px;text-align:left}
.tag{font-size:.7rem;padding:1px 6px;border-radius:8px;background:#30363d;color:#aab}
.evt{font-family:ui-monospace,monospace;font-size:.78rem;margin:2px 0}
.evt.fetched::before{content:"✓ ";color:#2ea043}
.evt.deferred::before{content:"⏸ ";color:#bb8009}
.evt.frontier-empty::before{content:"∅ ";color:#888}
label{font-size:.85rem;color:#aab}
</style>
</head><body>
<h1>🕷️ 網頁爬蟲 Web Crawler · Demo</h1>
<p>單 process 內用<b>內建假網路圖</b>（不連真網路）做確定性 BFS 爬取。
真實邏輯：<code>BFS frontier</code>、<code>bloom filter 去重</code>、<code>politeness/crawl-delay</code>、
<code>依 domain 分片的分散式 frontier</code>、<code>freshness 重抓排程</code>。</p>

<div class="box"><span class="label">操作流程（建議照順序）</span>
1. <b>seed</b> 載入假網路（種子預設 <code>http://news.example/home</code>）→
2. <b>crawl 1 步</b>反覆按，看 frontier 逐步擴展、visited 成長 →
3. 注意同 domain 連按時出現 <b>politeness 延後</b>（deferred）→
4. 看回連 URL 被 <b>bloom + seen 去重</b>擋掉（不重複入列）→
5. 觀察 <b>shard 指派</b>：同 domain 的 url 永遠落在同一 shard。
</div>

<div class="row">
  種子（每行一個，留空=用預設入口）：
  <textarea id="seeds" rows="2" cols="40" placeholder="http://news.example/home"></textarea>
  <button onclick="seed()">📥 seed 載入假網路</button>
</div>
<div class="row">
  crawl <input id="steps" value="1" size="3"> 步
  <button onclick="crawl()">▶️ 爬</button>
  <button onclick="crawl(5)">▶️▶️ 爬 5 步</button>
  &nbsp;|&nbsp;
  <button onclick="reset()">🔄 reset</button>
</div>

<h2>統計 <span id="clock" class="tag"></span></h2>
<div id="stats"></div>

<div class="cols">
  <div class="col">
    <h3>分散式 Frontier（依 domain 分片）</h3>
    <div id="frontier"></div>
  </div>
  <div class="col">
    <h3>Per-domain politeness 狀態</h3>
    <div id="domains"></div>
    <h3 style="margin-top:1em">已訪問 visited</h3>
    <div id="visited"></div>
  </div>
</div>

<h2>本次步進事件</h2>
<div id="events"><span class="tag">（按「爬」後顯示每步發生什麼）</span></div>

<h2>原始輸出</h2>
<pre id="out">（操作結果顯示於此）</pre>

<p style="color:#888;font-size:.82rem;margin-top:2em">※ 誠實聲明：本 demo <b>不連真網路</b>，用 process 內建的假網路圖（url→內容+外連 links）做確定性爬取。
但 <b>BFS frontier、bloom filter 去重（含偽陽性二次確認）、politeness/crawl-delay、依 domain 分片的分散式 frontier、freshness 重抓排程、爬蟲陷阱深度限制皆為真實邏輯</b>。
狀態持久化於 <code>data/crawler.json</code>（flock 保護）。</p>

<script>
const out = document.getElementById('out');
function show(o){ out.textContent = JSON.stringify(o, null, 2); }
async function post(url, body){ const r = await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body||{})}); return r.json(); }
async function get(url){ const r = await fetch(url); return r.json(); }
function v(id){ return document.getElementById(id).value; }
function dcls(d){ return d>=2?'d2':(d===1?'d1':'d0'); }

function renderState(st){
  document.getElementById('clock').textContent =
    '邏輯時鐘 t=' + st.clock + ' ｜ shard×' + st.config.shardCount + ' ｜ crawl-delay=' + st.config.crawlDelay + ' ｜ maxDepth=' + st.config.maxDepth;

  // 統計
  const b = st.bloom;
  document.getElementById('stats').innerHTML =
    '<span class="stat">Frontier 待爬 <b>'+st.frontierTotal+'</b></span>'+
    '<span class="stat">已訪問 <b>'+st.visitedCount+'</b></span>'+
    '<span class="stat">已見過(seen) <b>'+st.seenCount+'</b></span>'+
    '<span class="stat">Bloom 位元 <b>'+b.set_bits+'</b>/'+b.m_bits+'（'+(b.fill_ratio*100).toFixed(1)+'%）</span>'+
    '<span class="stat">Bloom 偽陽性率 ≈ <b>'+b.false_positive_rate+'</b></span>'+
    '<span class="stat">Bloom 命中再查 <b>'+b.lookups+'</b> ｜ 確認偽陽性 <b>'+b.confirmed_false_positives+'</b></span>';

  // frontier 分片
  const fb = document.getElementById('frontier'); fb.innerHTML = '';
  for (const s of st.frontier){
    const div = document.createElement('div'); div.className = 'shard';
    let items = s.items.map(it =>
      '<span class="u '+(it.deferred?'deferred':'')+' '+dcls(it.depth)+'">'
      +'[d'+it.depth+'] '+it.url+(it.deferred?' ⏸延後':'')+'</span>').join('');
    if (!items) items = '<span class="tag">（空）</span>';
    div.innerHTML = '<h4>shard #'+s.shard+'（'+s.size+' 個）</h4>'+items;
    fb.appendChild(div);
  }

  // per-domain politeness
  const db = document.getElementById('domains');
  let drows = '<table><tr><th>domain</th><th>shard</th><th>上次抓取 t</th></tr>';
  for (const d of st.domains){
    drows += '<tr><td>'+d.domain+'</td><td>#'+d.shard+'</td><td>'+(d.lastFetch===null?'-':d.lastFetch)+'</td></tr>';
  }
  drows += '</table>';
  db.innerHTML = st.domains.length ? drows : '<span class="tag">（尚無）</span>';

  // visited
  const vb = document.getElementById('visited');
  let vrows = '<table><tr><th>url</th><th>深</th><th>抓於</th><th>重抓於</th><th>次數</th></tr>';
  for (const x of st.visited){
    vrows += '<tr><td class="'+dcls(x.depth)+'">'+x.url+'</td><td>'+x.depth+'</td><td>'+x.fetchedAt+'</td><td>'+x.nextRecrawlAt+'</td><td>'+x.fetchCount+'</td></tr>';
  }
  vrows += '</table>';
  vb.innerHTML = st.visited.length ? vrows : '<span class="tag">（尚無）</span>';
}

function renderEvents(events){
  const eb = document.getElementById('events'); eb.innerHTML = '';
  if (!events || !events.length){ eb.innerHTML = '<span class="tag">（無事件）</span>'; return; }
  for (const e of events){
    const div = document.createElement('div'); div.className = 'evt ' + e.type;
    if (e.type === 'fetched'){
      const added = (e.addedToFrontier||[]).map(a=>a.url+'→shard#'+a.shard).join('、') || '（無新連結）';
      const skip = (e.skipped||[]).map(s=>s.url+'('+s.reason+')').join('、');
      div.innerHTML = 't='+e.clock+' 抓取 <b>'+e.url+'</b>（'+e.content+'）shard#'+e.shard+' d'+e.depth+
        '<br>　加入 frontier：'+added + (skip?'<br>　去重/陷阱擋下：'+skip:'');
    } else if (e.type === 'deferred'){
      div.innerHTML = 't='+e.clock+' ⏸ '+e.msg;
    } else {
      div.innerHTML = 't='+(e.clock||'')+' '+(e.msg||e.type);
    }
    eb.appendChild(div);
  }
}

async function refresh(){ renderState(await get('/api/state')); }
async function seed(){
  const lines = v('seeds').split('\\n').map(s=>s.trim()).filter(Boolean);
  const r = await post('/api/seed',{seeds:lines});
  renderState(r.state); renderEvents([]); show(r);
}
async function crawl(n){
  const steps = n || +v('steps');
  const r = await post('/api/crawl',{steps:steps});
  renderState(r.state); renderEvents(r.events); show({steps:r.steps, events:r.events});
}
async function reset(){ const r = await post('/api/reset',{}); renderState(r.state); renderEvents([]); show(r); }
refresh();
</script>
</body></html>
HTML;
}
