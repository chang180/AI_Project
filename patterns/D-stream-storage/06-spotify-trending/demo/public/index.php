<?php
declare(strict_types=1);

/**
 * Spotify 趨勢榜 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8006 -t public
 *
 * 聚焦：Count-Min Sketch 近似計數 + Top-K（Heavy Hitters）+ 指數時間衰減。
 *
 * 路由：
 *   GET  /                          深色測試頁
 *   POST /api/seed                  模擬一批播放事件（不同歌不同次數）
 *   POST /api/play   { song, n }    手動餵某首歌 n 次播放
 *   GET  /api/score?song=...        某歌的「sketch 估計 vs 精確計數」誤差對比
 *   GET  /api/trending              目前 Top-K 榜（含 sketch 估計值）
 *   POST /api/decay  { lambda }     套用一次時間衰減，看排名變化
 *   POST /api/reset                 清空狀態
 */

require __DIR__ . '/../src/CountMinSketch.php';
require __DIR__ . '/../src/TopK.php';
require __DIR__ . '/../src/EventStore.php';

const TOPK_K = 10;

$store = new EventStore(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- 載入串流算子狀態並還原 sketch / Top-K ----
$state  = $store->load();
$sketch = CountMinSketch::fromArray($state['sketch']);
$topk   = new TopK($sketch, TOPK_K);
$topk->loadCandidates($state['candidates'] ?? []);
/** @var array<string,int> 精確計數（僅供對比誤差，真實系統不會留） */
$exact  = $state['exact'] ?? [];

/** 把目前記憶體狀態寫回 store */
$persist = function () use ($store, $sketch, $topk, &$exact, &$state): void {
    $state['sketch']     = $sketch->toArray();
    $state['candidates'] = $topk->candidatesArray();
    $state['exact']      = $exact;
    $store->save($state);
};

// ---- POST /api/seed：模擬一批播放事件 ----
if ($method === 'POST' && $path === '/api/seed') {
    // 模擬曲庫：不同歌曲熱度差異很大（長尾分布），更貼近真實
    $catalog = [
        's_blinding_lights' => 1200, 's_shape_of_you' => 950, 's_dance_monkey' => 700,
        's_levitating'      => 540,  's_bad_guy'      => 480, 's_uptown_funk' => 360,
        's_perfect'         => 250,  's_senorita'     => 180, 's_circles'    => 120,
        's_memories'        => 90,   's_someone_you'  => 60,  's_old_town'   => 40,
        's_sunflower'       => 25,   's_happier'      => 12,  's_lovely'     => 5,
    ];
    $added = 0;
    foreach ($catalog as $song => $plays) {
        // 加一點隨機抖動，讓每次 seed 略有不同
        $n = max(1, $plays + random_int(-(int) ($plays * 0.1), (int) ($plays * 0.1)));
        $topk->offer($song, $n);                 // sketch.add + 維護 Top-K
        $exact[$song] = ($exact[$song] ?? 0) + $n; // 精確計數（對比用）
        $added += $n;
    }
    $state['events'] += $added;
    $persist();
    exit(json_out(['ok' => true, 'events_added' => $added, 'total_events' => $state['events']]));
}

// ---- POST /api/play：手動餵某首歌 ----
if ($method === 'POST' && $path === '/api/play') {
    $in   = read_input();
    $song = trim((string) ($in['song'] ?? ''));
    $n    = max(1, (int) ($in['n'] ?? 1));
    if ($song === '') {
        http_response_code(400);
        exit(json_out(['error' => 'song 不可為空']));
    }
    $topk->offer($song, $n);
    $exact[$song] = ($exact[$song] ?? 0) + $n;
    $state['events'] += $n;
    $persist();
    exit(json_out([
        'ok' => true, 'song' => $song, 'added' => $n,
        'estimate' => $sketch->estimate($song), 'exact' => $exact[$song],
    ]));
}

// ---- GET /api/score：sketch 估計 vs 精確計數，看誤差 ----
if ($method === 'GET' && $path === '/api/score') {
    $song = trim((string) ($_GET['song'] ?? ''));
    if ($song === '') {
        http_response_code(400);
        exit(json_out(['error' => 'song 不可為空']));
    }
    $est = $sketch->estimate($song);
    $tru = (int) ($exact[$song] ?? 0);
    exit(json_out([
        'song'       => $song,
        'estimate'   => $est,                       // Count-Min Sketch 近似值（≥ 真值）
        'exact'      => $tru,                        // 精確計數
        'over_count' => $est - $tru,                 // 偏大量（碰撞造成）
        'error_pct'  => $tru > 0 ? round(($est - $tru) / $tru * 100, 3) : 0.0,
    ]));
}

// ---- GET /api/trending：目前 Top-K 榜 ----
if ($method === 'GET' && $path === '/api/trending') {
    $rows = [];
    foreach ($topk->top() as $r) {
        $song = $r['song'];
        $rows[] = [
            'rank'     => $r['rank'],
            'song'     => $song,
            'estimate' => $r['score'],
            'exact'    => (int) ($exact[$song] ?? 0),
        ];
    }
    exit(json_out([
        'k'            => $topk->k(),
        'total_events' => $state['events'] ?? 0,
        'decay_rounds' => $state['decay_rounds'] ?? 0,
        'items'        => $rows,
    ]));
}

// ---- POST /api/decay：套用一次時間衰減 ----
if ($method === 'POST' && $path === '/api/decay') {
    $in     = read_input();
    $lambda = (float) ($in['lambda'] ?? 0.8);
    if ($lambda <= 0 || $lambda >= 1) {
        $lambda = 0.8; // 合理範圍：0 < λ < 1
    }
    $topk->decay($lambda);              // sketch 整體衰減 + 刷新候選分數
    $state['decay_rounds'] = ($state['decay_rounds'] ?? 0) + 1;
    // 精確計數同步衰減，對比才公平
    foreach ($exact as $s => $v) {
        $exact[$s] = (int) floor($v * $lambda);
    }
    $persist();
    exit(json_out(['ok' => true, 'lambda' => $lambda, 'decay_rounds' => $state['decay_rounds']]));
}

// ---- POST /api/reset：清空 ----
if ($method === 'POST' && $path === '/api/reset') {
    $store->reset();
    exit(json_out(['ok' => true, 'message' => '狀態已重置']));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($sketch);
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
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST;
}

function render_home(CountMinSketch $sketch): void
{
    $cells = number_format($sketch->cells());
    $w = $sketch->width();
    $d = $sketch->depth();
    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Spotify 趨勢榜 · Demo</title>
<style>
:root{--bg:#0f1419;--soft:#1a2027;--card:#1e2630;--bd:#2a3540;--tx:#e6edf3;--dim:#9aa7b3;--ac:#58a6ff;--ac2:#7ee787;--wn:#f0883e}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.7}
.wrap{max-width:860px;margin:0 auto;padding:28px 20px 80px}
h1{font-size:1.7rem;margin:.2em 0}h2{font-size:1.2rem;border-bottom:1px solid var(--bd);padding-bottom:.3em;margin:1.6em 0 .6em}
.sub{color:var(--dim)}code{background:#161b22;color:var(--ac2);padding:2px 6px;border-radius:6px;font-size:.88em}
.box{border-left:4px solid var(--ac);background:var(--soft);padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box.tip{border-color:var(--ac2)}.box.warn{border-color:var(--wn)}.box .label{font-weight:700;display:block;margin-bottom:.2em}
button{background:var(--ac);color:#0b0e13;border:0;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer;margin:4px 4px 4px 0}
button.alt{background:var(--soft);color:var(--tx);border:1px solid var(--bd)}
input{background:#161b22;color:var(--tx);border:1px solid var(--bd);border-radius:6px;padding:7px 10px}
table{width:100%;border-collapse:collapse;margin:1em 0;font-size:.92rem}th,td{border:1px solid var(--bd);padding:8px 10px;text-align:left}
th{background:var(--soft);color:var(--ac)}tr:nth-child(even) td{background:rgba(255,255,255,.02)}
pre{background:#161b22;border:1px solid var(--bd);border-radius:10px;padding:12px 14px;overflow:auto;font-size:.82rem;white-space:pre-wrap}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
</style></head><body><div class="wrap">
<h1>🎧 Spotify 趨勢榜 · Demo</h1>
<p class="sub">Count-Min Sketch 近似計數 + Top-K（Heavy Hitters）+ 指數時間衰減</p>
<div class="box tip"><span class="label">目前 sketch 配置</span>
寬度 w=<code>$w</code> × 深度 d=<code>$d</code> = <code>$cells</code> 格（固定記憶體，<strong>與歌曲數無關</strong>）。</div>

<h2>① 模擬播放事件</h2>
<p>餵入一批不同熱度的歌（長尾分布），累積到串流計數。</p>
<div class="row">
  <button onclick="seed()">模擬一批播放</button>
  <input id="song" placeholder="song_id（如 s_levitating）" size="22">
  <input id="n" type="number" value="100" min="1" style="width:90px">
  <button class="alt" onclick="play()">手動餵這首</button>
</div>

<h2>② sketch 估計 vs 精確（看近似誤差）</h2>
<div class="row">
  <input id="qsong" placeholder="song_id" size="22" value="s_levitating">
  <button class="alt" onclick="score()">查誤差</button>
</div>

<h2>③ 目前 Top-K 榜</h2>
<div class="row">
  <button onclick="trending()">刷新榜單</button>
  <input id="lambda" type="number" value="0.8" min="0.01" max="0.99" step="0.05" style="width:90px">
  <button class="alt" onclick="decay()">套用時間衰減</button>
  <button class="alt" onclick="reset()">重置</button>
</div>
<div id="board"></div>
<pre id="out">（操作結果會顯示在這裡）</pre>

<div class="box warn"><span class="label">誠實聲明</span>
單機單行程、用 JSON 檔保存串流算子狀態示意，非真的接 Kafka/Flink；但
<strong>Count-Min Sketch 計數與取 min 估計、Top-K 的 heap 維護、指數衰減皆為真實演算法</strong>。
榜單中 <code>estimate</code> 為 sketch 近似值（≥ 真值），<code>exact</code> 為精確計數，可直接比對誤差。</div>

<script>
const out=document.getElementById('out'), board=document.getElementById('board');
const show=o=>out.textContent=JSON.stringify(o,null,2);
async function jget(u){const r=await fetch(u);return r.json()}
async function jpost(u,b){const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})});return r.json()}
async function seed(){show(await jpost('/api/seed'));trending()}
async function play(){const song=document.getElementById('song').value.trim();const n=+document.getElementById('n').value||1;if(!song)return show({error:'請填 song_id'});show(await jpost('/api/play',{song,n}));trending()}
async function score(){const song=document.getElementById('qsong').value.trim();show(await jget('/api/score?song='+encodeURIComponent(song)))}
async function decay(){const lambda=+document.getElementById('lambda').value||0.8;show(await jpost('/api/decay',{lambda}));trending()}
async function reset(){show(await jpost('/api/reset'));board.innerHTML=''}
async function trending(){const d=await jget('/api/trending');let h='<table><tr><th>名次</th><th>song_id</th><th>sketch 估計</th><th>精確</th><th>誤差</th></tr>';
 for(const it of d.items){const err=it.estimate-it.exact;h+=`<tr><td>\${it.rank}</td><td><code>\${it.song}</code></td><td>\${it.estimate}</td><td>\${it.exact}</td><td>\${err>=0?'+':''}\${err}</td></tr>`}
 h+=`</table><p class="sub">總事件 \${d.total_events} · 已衰減 \${d.decay_rounds} 輪 · Top-\${d.k}</p>`;board.innerHTML=h}
trending();
</script>
</div></body></html>
HTML;
}
