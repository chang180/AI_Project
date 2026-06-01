<?php
declare(strict_types=1);

/**
 * 地圖路徑規劃 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8039 -t public
 *
 * 路由：
 *   GET  /                                   測試頁（選起訖 → Dijkstra/A* 比較 + 調路況看 ETA）
 *   POST /api/graph                          以 { nodes:[{id,lat,lng}], edges:[{a,b,speed}] } 重建道路圖
 *   GET  /api/route?from=&to=&algo=&metric=  單次路由（algo=dijkstra|astar；metric=time|distance）
 *   GET  /api/compare?from=&to=&metric=      同時跑兩演算法並比較探索節點數
 *   POST /api/traffic                        套用即時路況 { items:[{a,b,factor}], reset:bool }
 *   GET  /api/tile?z=&x=&y=                  地圖瓦片：回該瓦片內的節點/邊（slippy map 概念）
 *   GET  /api/state                          目前圖狀態（節點、邊、路況）
 */

require __DIR__ . '/../src/Graph.php';
require __DIR__ . '/../src/Router.php';

$graph  = new Graph(__DIR__ . '/../data');
$router  = new Router($graph);

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($path, '/') ?: '/';

// ---- POST /api/graph：重建圖 ----
if ($method === 'POST' && $path === '/api/graph') {
    $body  = json_body();
    $nodes = $body['nodes'] ?? [];
    $edges = $body['edges'] ?? [];
    if (!is_array($nodes) || !is_array($edges) || $nodes === []) {
        http_response_code(400);
        exit(json_out(['error' => '需要 nodes[] 與 edges[]']));
    }
    $graph->replace($nodes, $edges);
    exit(json_out(['ok' => true, 'nodes' => count($graph->nodes()), 'edges' => count($graph->edges())]));
}

// ---- GET /api/route：單次路由 ----
if ($method === 'GET' && $path === '/api/route') {
    $from   = (int) ($_GET['from'] ?? 0);
    $to     = (int) ($_GET['to'] ?? 0);
    $algo   = ($_GET['algo'] ?? 'dijkstra') === 'astar' ? 'astar' : 'dijkstra';
    $metric = ($_GET['metric'] ?? 'time') === 'distance' ? 'distance' : 'time';
    exit(json_out($router->route($from, $to, $algo, $metric)));
}

// ---- GET /api/compare：兩演算法比較 ----
if ($method === 'GET' && $path === '/api/compare') {
    $from   = (int) ($_GET['from'] ?? 0);
    $to     = (int) ($_GET['to'] ?? 0);
    $metric = ($_GET['metric'] ?? 'time') === 'distance' ? 'distance' : 'time';
    exit(json_out($router->compare($from, $to, $metric)));
}

// ---- POST /api/traffic：套用即時路況 ----
if ($method === 'POST' && $path === '/api/traffic') {
    $body  = json_body();
    $items = $body['items'] ?? [];
    $reset = (bool) ($body['reset'] ?? false);
    if (!is_array($items)) {
        http_response_code(400);
        exit(json_out(['error' => '需要 items[]']));
    }
    $applied = $graph->applyTraffic($items, $reset);
    exit(json_out(['ok' => true, 'applied' => $applied, 'reset' => $reset]));
}

// ---- GET /api/tile：地圖瓦片 ----
if ($method === 'GET' && $path === '/api/tile') {
    $z = (int) ($_GET['z'] ?? 14);
    $x = (int) ($_GET['x'] ?? 0);
    $y = (int) ($_GET['y'] ?? 0);
    exit(json_out($graph->tile($z, $x, $y)));
}

// ---- GET /api/state：圖狀態 ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out([
        'nodes' => array_values($graph->nodes()),
        'edges' => $graph->edges(),
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

function json_body(): array
{
    $raw = (string) file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function render_home(): void
{
    echo <<<'HTML'
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>地圖路徑規劃 · Demo</title>
<style>
 body{max-width:980px;margin:32px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#0d1117;color:#e6edf3}
 h1{font-size:1.5rem}h2{font-size:1.1rem;margin-top:1.6em;border-bottom:1px solid #30363d;padding-bottom:4px}
 a{color:#58a6ff}code{background:#161b22;padding:2px 6px;border-radius:4px}
 .row{display:flex;gap:24px;flex-wrap:wrap}
 .panel{flex:1;min-width:300px}
 svg{background:#161b22;border:1px solid #30363d;border-radius:8px;width:100%;height:auto}
 label{font-size:.9rem}select,button,input{font-size:.95rem;padding:6px 8px;background:#161b22;color:#e6edf3;border:1px solid #30363d;border-radius:6px}
 button{cursor:pointer;background:#238636;border-color:#238636}button.alt{background:#1f6feb;border-color:#1f6feb}button.warn{background:#9e6a03;border-color:#9e6a03}
 table{width:100%;border-collapse:collapse;margin-top:8px;font-size:.9rem}
 td,th{border:1px solid #30363d;padding:6px 8px;text-align:left}
 .dij{color:#f0883e}.ast{color:#3fb950}
 .pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:.8rem;background:#21262d}
 .note{color:#8b949e;font-size:.82rem}
 .edge{stroke:#3d444d;stroke-width:2}
 .edge.cong{stroke:#d29922}
 .pathD{stroke:#f0883e;stroke-width:5;fill:none;opacity:.85}
 .pathA{stroke:#3fb950;stroke-width:3;fill:none;opacity:.95}
 .nd{fill:#484f58}.nd.from{fill:#58a6ff}.nd.to{fill:#db61a2}
 .ndlabel{fill:#8b949e;font-size:9px}
</style></head><body>
<h1>🗺️ 地圖路徑規劃 · Demo <span class="pill">Dijkstra vs A*</span></h1>
<p class="note">預設為 6×5 格狀城市道路網（30 路口）。選起點/終點 → 比較兩演算法<strong>探索的節點數</strong>；
調整路段壅塞 → 看最快路徑與 ETA 如何變化。橘=Dijkstra 路徑、綠=A* 路徑、黃邊=壅塞路段。</p>

<div class="row">
 <div class="panel">
  <svg id="map" viewBox="0 0 520 440" preserveAspectRatio="xMidYMid meet"></svg>
 </div>
 <div class="panel">
  <h2>1. 路徑查詢</h2>
  <div>
   <label>起點 <select id="from"></select></label>
   <label>終點 <select id="to"></select></label>
   <label>目標 <select id="metric">
     <option value="time">最快（time，受路況）</option>
     <option value="distance">最短（distance）</option>
   </select></label>
  </div>
  <p><button id="run">比較 Dijkstra vs A*</button></p>
  <table id="cmp"><tr><th>演算法</th><th>探索節點</th><th>成本</th><th>路徑長</th></tr>
   <tr><td class="dij">Dijkstra</td><td id="dExp">-</td><td id="dCost">-</td><td id="dHop">-</td></tr>
   <tr><td class="ast">A*</td><td id="aExp">-</td><td id="aCost">-</td><td id="aHop">-</td></tr>
  </table>
  <p id="cmpNote" class="note"></p>

  <h2>2. 即時路況 → ETA 變化</h2>
  <p class="note">把路徑上某段設為壅塞（係數 ×N），time 權重變大 → ETA 上升、A* 可能改走別條路。</p>
  <div>
   <label>路段 <select id="cseg"></select></label>
   <label>壅塞係數 <input id="cfac" type="number" value="3" min="0.1" step="0.5" style="width:64px"></label>
   <button class="warn" id="setc">套用壅塞</button>
   <button class="alt" id="reset">清除全部路況</button>
  </div>
  <p id="trafficNote" class="note"></p>
 </div>
</div>

<h2>3. 直接打 API</h2>
<pre class="note">GET  /api/route?from=1&to=30&algo=astar&metric=time
GET  /api/compare?from=1&to=30&metric=time
POST /api/traffic   {"items":[{"a":1,"b":2,"factor":3}],"reset":false}
GET  /api/tile?z=14&x=13713&y=7050
GET  /api/state</pre>

<script>
let STATE = {nodes:[], edges:[]};
let lastPaths = {D:[], A:[]};
const W=520,H=440,PAD=30;

async function load(){
  STATE = await (await fetch('/api/state')).json();
  fillSelects();
  draw();
}
function bounds(){
  const lats=STATE.nodes.map(n=>n.lat), lngs=STATE.nodes.map(n=>n.lng);
  return {minLat:Math.min(...lats),maxLat:Math.max(...lats),minLng:Math.min(...lngs),maxLng:Math.max(...lngs)};
}
function proj(n){
  const b=bounds();
  const x=PAD+(n.lng-b.minLng)/((b.maxLng-b.minLng)||1)*(W-2*PAD);
  const y=PAD+(b.maxLat-n.lat)/((b.maxLat-b.minLat)||1)*(H-2*PAD); // 緯度大在上
  return [x,y];
}
const nodeById=id=>STATE.nodes.find(n=>n.id===id);

function fillSelects(){
  const opts=STATE.nodes.map(n=>`<option value="${n.id}">#${n.id}</option>`).join('');
  from.innerHTML=opts; to.innerHTML=opts;
  from.value=STATE.nodes[0].id;
  // 預設選格網中段節點當終點：A* 朝終點擴張，探索節點數明顯少於 Dijkstra（角落對角是 A* 最壞情況）
  to.value=(STATE.nodes.find(n=>n.id===15)?STATE.nodes.find(n=>n.id===15).id:STATE.nodes[Math.floor(STATE.nodes.length/2)].id);
  cseg.innerHTML=STATE.edges.map(e=>`<option value="${e.a}-${e.b}">#${e.a}↔#${e.b} (x${e.congestion})</option>`).join('');
}
function draw(){
  const svg=document.getElementById('map');
  let s='';
  for(const e of STATE.edges){
    const [x1,y1]=proj(nodeById(e.a)), [x2,y2]=proj(nodeById(e.b));
    s+=`<line class="edge ${e.congestion>1.0?'cong':''}" x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}"/>`;
  }
  s+=pathLine(lastPaths.D,'pathD');
  s+=pathLine(lastPaths.A,'pathA');
  for(const n of STATE.nodes){
    const [x,y]=proj(n);
    let cls='nd';
    if(n.id===+from.value)cls+=' from';
    if(n.id===+to.value)cls+=' to';
    s+=`<circle class="${cls}" cx="${x}" cy="${y}" r="6"/><text class="ndlabel" x="${x+7}" y="${y-7}">${n.id}</text>`;
  }
  svg.innerHTML=s;
}
function pathLine(path,cls){
  if(!path||path.length<2)return'';
  const pts=path.map(id=>proj(nodeById(id)).join(',')).join(' ');
  return `<polyline class="${cls}" points="${pts}"/>`;
}

async function run(){
  const m=metric.value;
  const r=await (await fetch(`/api/compare?from=${from.value}&to=${to.value}&metric=${m}`)).json();
  dExp.textContent=r.dijkstra.explored; aExp.textContent=r.astar.explored;
  const unit=m==='distance'?' m':' s';
  dCost.textContent=r.dijkstra.cost+unit; aCost.textContent=r.astar.cost+unit;
  dHop.textContent=r.dijkstra.hops; aHop.textContent=r.astar.hops;
  lastPaths.D=r.dijkstra.path; lastPaths.A=r.astar.path;
  cmpNote.innerHTML=`A* 比 Dijkstra 少探索 <strong>${r.explored_saved_pct}%</strong> 節點`
    +`（${r.dijkstra.explored}→${r.astar.explored}），路徑${r.same_path?'相同':'不同'}、成本${r.same_cost?'相同':'不同'}。`;
  draw();
}
async function setCongestion(){
  const [a,b]=cseg.value.split('-').map(Number);
  await fetch('/api/traffic',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({items:[{a,b,factor:+cfac.value}]})});
  await load();
  trafficNote.textContent=`已將 #${a}↔#${b} 設為壅塞 ×${cfac.value}，重新查詢看 ETA 變化。`;
  run();
}
async function resetTraffic(){
  await fetch('/api/traffic',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({items:[],reset:true})});
  await load(); trafficNote.textContent='已清除全部路況（壅塞係數回 1.0）。'; run();
}
document.getElementById('run').onclick=run;
document.getElementById('setc').onclick=setCongestion;
document.getElementById('reset').onclick=resetTraffic;
from.onchange=draw; to.onchange=draw;
load().then(run);
</script>
</body></html>
HTML;
}
