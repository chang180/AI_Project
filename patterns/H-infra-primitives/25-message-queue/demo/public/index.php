<?php
declare(strict_types=1);

/**
 * 分散式訊息佇列 Kafka Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8025 -t public
 *
 * 路由：
 *   GET  /                  測試頁（建 topic、produce、依 group 消費看 offset、replay、rebalance 指派表）
 *   POST /api/produce       produce 一筆 { topic, key, value }  → 看落在哪分區、配到哪個 offset
 *   POST /api/consume       poll + commit { group, topic }      → 回傳新訊息並推進 offset
 *   POST /api/seek          replay { group, topic, to }         → 把 offset seek 回 to（預設 0）
 *   GET  /api/assign        rebalance 指派 ?topic=&consumers=c1,c2&strategy=range|roundrobin
 *   GET  /api/status        某 group 各分區 committed / logEnd / lag ?group=&topic=
 */

require __DIR__ . '/../src/Broker.php';

$broker = new Broker(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- POST /api/produce ----
if ($method === 'POST' && $path === '/api/produce') {
    $in = read_body();
    $topic = trim((string) ($in['topic'] ?? 'orders'));
    $key = isset($in['key']) ? (string) $in['key'] : null;
    if ($key === '') {
        $key = null;
    }
    $value = $in['value'] ?? '';
    $partitions = (int) ($in['partitions'] ?? 0);
    if ($partitions > 0) {
        $broker->createTopic($topic, $partitions);
    }
    if ($broker->partitionCount($topic) < 1) {
        $broker->createTopic($topic, 3);
    }
    $rec = $broker->produce($topic, $key, $value);
    exit(json_out([
        'produced' => $rec,
        'note' => 'partition = crc32(key) % N（同 key 必同分區，分區內有序）',
    ]));
}

// ---- POST /api/consume：poll + commit ----
if ($method === 'POST' && $path === '/api/consume') {
    $in = read_body();
    $group = trim((string) ($in['group'] ?? 'g1'));
    $topic = trim((string) ($in['topic'] ?? 'orders'));
    $doCommit = (bool) ($in['commit'] ?? true);

    $polled = $broker->poll($group, $topic);
    if ($doCommit && $polled['messages']) {
        $broker->commit($group, $topic, $polled['nextOffsets']);
    }
    exit(json_out([
        'group' => $group,
        'topic' => $topic,
        'messages' => $polled['messages'],
        'committed' => $doCommit,
        'status' => $broker->groupStatus($group, $topic),
    ]));
}

// ---- POST /api/seek：replay ----
if ($method === 'POST' && $path === '/api/seek') {
    $in = read_body();
    $group = trim((string) ($in['group'] ?? 'g1'));
    $topic = trim((string) ($in['topic'] ?? 'orders'));
    $to = (int) ($in['to'] ?? 0);
    $broker->seek($group, $topic, $to);
    exit(json_out([
        'group' => $group,
        'topic' => $topic,
        'seekTo' => $to,
        'status' => $broker->groupStatus($group, $topic),
        'note' => '已把 offset seek 回 ' . $to . '，下次 consume 會重新讀到該位移之後的全部訊息',
    ]));
}

// ---- GET /api/assign：rebalance 指派 ----
if ($method === 'GET' && $path === '/api/assign') {
    $topic = trim((string) ($_GET['topic'] ?? 'orders'));
    $strategy = (string) ($_GET['strategy'] ?? 'range');
    $consumers = array_values(array_filter(array_map(
        'trim',
        explode(',', (string) ($_GET['consumers'] ?? 'c1,c2'))
    ), static fn($s) => $s !== ''));
    exit(json_out([
        'topic' => $topic,
        'partitions' => $broker->partitionCount($topic),
        'consumers' => $consumers,
        'strategy' => $strategy,
        'assignment' => $broker->assign($topic, $consumers, $strategy),
    ]));
}

// ---- GET /api/status ----
if ($method === 'GET' && $path === '/api/status') {
    $group = trim((string) ($_GET['group'] ?? 'g1'));
    $topic = trim((string) ($_GET['topic'] ?? 'orders'));
    exit(json_out([
        'group' => $group,
        'topic' => $topic,
        'status' => $broker->groupStatus($group, $topic),
    ]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($broker);
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

function render_home(Broker $broker): void
{
    $topics = $broker->topics();
    $topicRows = '';
    foreach ($topics as $t => $n) {
        $topicRows .= '<tr><td><code>' . htmlspecialchars((string) $t) . '</code></td>'
            . '<td>' . (int) $n . ' 分區</td></tr>';
    }
    if ($topicRows === '') {
        $topicRows = '<tr><td colspan="2" style="color:#888">尚無主題，請先用下方表單 produce（會自動建 3 分區的 <code>orders</code>）</td></tr>';
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>分散式訊息佇列 Kafka · Demo</title>
<style>
body{max-width:880px;margin:36px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#16181c;color:#e6e6e6;line-height:1.6}
h1{font-size:1.5rem}h2{font-size:1.1rem;margin-top:1.8em;border-bottom:1px solid #333;padding-bottom:.3em}
code{background:#222;padding:2px 6px;border-radius:4px;color:#7ee787}
.box{border-left:4px solid #58a6ff;background:#1b1d22;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
table{width:100%;border-collapse:collapse;margin:.6em 0}td,th{border:1px solid #333;padding:7px 9px;text-align:left;font-size:.92rem}
th{background:#20242b}
input,select,button{font-size:.92rem;padding:6px 8px;border-radius:6px;border:1px solid #444;background:#0f1115;color:#e6e6e6;margin:2px}
button{background:#1f6feb;border-color:#1f6feb;cursor:pointer}button:hover{background:#388bfd}
.row{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:.4em 0}
pre{background:#0f1115;border:1px solid #333;border-radius:8px;padding:12px;overflow:auto;font-size:.85rem;max-height:340px}
label{font-size:.85rem;color:#aab}
</style>
</head><body>
<h1>📨 分散式訊息佇列 Kafka · Demo</h1>
<p>真實邏輯：<code>partition = crc32(key) % N</code> 依 key 分區（同 key 必同分區→<strong>分區內有序</strong>）、單調遞增 <strong>offset</strong>、消費者組各記 <strong>committed offset</strong>、<strong>replay</strong> 重放、<strong>rebalance</strong> 指派。</p>

<h2>現有主題</h2>
<table><tr><th>topic</th><th>分區數</th></tr>$topicRows</table>

<h2>① Produce（依 key 分區）</h2>
<div class="row">
  topic <input id="p_topic" value="orders" size="10">
  分區數 N <input id="p_parts" value="3" size="3">
  key <input id="p_key" value="user-42" size="10">
  value <input id="p_val" value="下單 #1" size="14">
  <button onclick="produce()">produce</button>
</div>
<div class="box"><span class="label">提示</span>同一個 key 多次 produce 會落在同一分區、offset 遞增；換 key 可能落到別的分區。試試 key 改成 <code>user-7</code>、<code>user-99</code> 看落點。</div>

<h2>② Consume（poll + commit，看 offset 前進）</h2>
<div class="row">
  group <input id="c_group" value="g1" size="8">
  topic <input id="c_topic" value="orders" size="10">
  <button onclick="consume()">poll + commit</button>
  <button onclick="status()">看進度/lag</button>
</div>

<h2>③ Replay（seek offset 回到位移）</h2>
<div class="row">
  group <input id="s_group" value="g1" size="8">
  topic <input id="s_topic" value="orders" size="10">
  seek 到 offset <input id="s_to" value="0" size="4">
  <button onclick="seek()">replay</button>
</div>

<h2>④ Rebalance（分區指派表）</h2>
<div class="row">
  topic <input id="a_topic" value="orders" size="10">
  consumers <input id="a_cons" value="c1,c2" size="12">
  <select id="a_strat"><option value="range">range</option><option value="roundrobin">roundrobin</option></select>
  <button onclick="assign()">指派</button>
</div>

<h2>輸出</h2>
<pre id="out">（操作結果顯示於此）</pre>

<p style="color:#888;font-size:.82rem;margin-top:2em">※ 單機用檔案模擬分區 log，無副本/網路/ZooKeeper；但分區、offset、消費者組、分區內有序、replay、rebalance 邏輯皆為真實可執行。</p>

<script>
const out = document.getElementById('out');
function show(o){ out.textContent = JSON.stringify(o, null, 2); }
async function post(url, body){ const r = await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); return r.json(); }
async function get(url){ const r = await fetch(url); return r.json(); }
async function produce(){
  const body={topic:v('p_topic'),partitions:+v('p_parts'),key:v('p_key'),value:v('p_val')};
  show(await post('/api/produce', body));
}
async function consume(){ show(await post('/api/consume',{group:v('c_group'),topic:v('c_topic')})); }
async function status(){ show(await get('/api/status?group='+enc('c_group')+'&topic='+enc('c_topic'))); }
async function seek(){ show(await post('/api/seek',{group:v('s_group'),topic:v('s_topic'),to:+v('s_to')})); }
async function assign(){ show(await get('/api/assign?topic='+enc('a_topic')+'&consumers='+encodeURIComponent(v('a_cons'))+'&strategy='+v('a_strat'))); }
function v(id){ return document.getElementById(id).value; }
function enc(id){ return encodeURIComponent(v(id)); }
</script>
</body></html>
HTML;
}
