<?php
declare(strict_types=1);

/**
 * CDC Data Pipeline Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8048 -t public
 *
 * 概念：對「來源表」做 insert/update/delete → 產生變更事件（op/before/after/LSN）
 *       append 到變更日誌；下游消費者依 LSN 依序、冪等套用到衍生視圖，達成最終一致。
 *
 * 路由：
 *   GET  /                 測試頁（改來源表、看變更事件流、下游套用、比對一致、重放看冪等）
 *   POST /api/mutate       對來源表寫入  { op:c|u|d, id, fields:{...} } → 產生變更事件
 *   GET  /api/changes      變更日誌（依 LSN 有序）
 *   POST /api/consume      下游消費：拉 LSN>offset 的事件依序冪等套用（mode=replay 則重放全部）
 *   GET  /api/state        現況：來源表 / 衍生視圖 / 消費位移 / 是否一致
 */

require __DIR__ . '/../src/SourceTable.php';
require __DIR__ . '/../src/ChangeLog.php';
require __DIR__ . '/../src/Consumer.php';

const TABLE = 'products';

$dataDir = __DIR__ . '/../data';
$table = new SourceTable($dataDir);
$log = new ChangeLog($dataDir);
$consumer = new Consumer($dataDir);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/mutate：對來源表寫入 → 產生變更事件 ----
if ($method === 'POST' && $path === '/api/mutate') {
    $in = read_input();
    $op = (string) ($in['op'] ?? '');
    $id = (int) ($in['id'] ?? 0);
    $fields = is_array($in['fields'] ?? null) ? $in['fields'] : [];

    if (!in_array($op, ['c', 'u', 'd'], true) || $id <= 0) {
        http_response_code(400);
        exit(json_out(['error' => 'op 需為 c|u|d，且 id 需 > 0']));
    }

    $existing = $table->get($id);
    if ($op === 'c' && $existing !== null) {
        http_response_code(409);
        exit(json_out(['error' => "id=$id 已存在，建立請用其它 id 或改用 update(u)"]));
    }
    if (($op === 'u' || $op === 'd') && $existing === null) {
        http_response_code(404);
        exit(json_out(['error' => "id=$id 不存在，無法 $op"]));
    }

    // 1) 套用到來源表，取得 before/after
    $delta = $table->apply($op, $id, $fields);
    // 2) 同步往變更日誌 append 一筆變更事件（這就是 CDC 擷取的動作）
    $event = $log->append($op, TABLE, $id, $delta['before'], $delta['after']);

    exit(json_out(['ok' => true, 'event' => $event]));
}

// ---- GET /api/changes：變更日誌 ----
if ($method === 'GET' && $path === '/api/changes') {
    exit(json_out(['head_lsn' => $log->headLsn(), 'changes' => $log->all()]));
}

// ---- POST /api/consume：下游套用 ----
if ($method === 'POST' && $path === '/api/consume') {
    $in = read_input();
    $mode = (string) ($in['mode'] ?? 'incremental');

    if ($mode === 'replay') {
        // 重放：把「全部」事件再餵一次（含已套用的）→ 驗證冪等不會套錯
        $events = $log->all();
    } else {
        // 增量：只拉 LSN > 目前位移 的事件
        $events = $log->since($consumer->appliedLsn());
    }

    $result = $consumer->consume($events);
    $result['mode'] = $mode;
    exit(json_out($result));
}

// ---- GET /api/state：現況 + 一致性比對 ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out(state_snapshot($table, $log, $consumer)));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($table, $log, $consumer);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============

function read_input(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST;
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/**
 * 比對「來源表」與「下游衍生視圖」是否一致（忽略視圖內部的 _lsn 戳記）。
 * 一致 = 兩邊的 pk 集合相同，且每列欄位相同。
 */
function state_snapshot(SourceTable $table, ChangeLog $log, Consumer $consumer): array
{
    $src = $table->all();
    $view = $consumer->view();

    $viewClean = [];
    foreach ($view as $pk => $doc) {
        unset($doc['_lsn']);
        $viewClean[$pk] = $doc;
    }

    // 正規化排序後比較
    $normalize = static function (array $rows): array {
        ksort($rows);
        foreach ($rows as &$r) {
            ksort($r);
        }
        return $rows;
    };
    $consistent = $normalize($src) == $normalize($viewClean);

    return [
        'consistent' => $consistent,
        'source_table' => $src,
        'derived_view' => $view,
        'consumer_offset' => $consumer->appliedLsn(),
        'head_lsn' => $log->headLsn(),
        'lag' => $log->headLsn() - $consumer->appliedLsn(),
    ];
}

function render_home(SourceTable $table, ChangeLog $log, Consumer $consumer): void
{
    $state = state_snapshot($table, $log, $consumer);
    $consistent = $state['consistent'];
    $badge = $consistent
        ? "<span style='color:#7ee787'>✔ 一致</span>"
        : "<span style='color:#ffa657'>✘ 尚未一致（下游有 lag={$state['lag']}，按「下游套用」）</span>";

    $changesJson = htmlspecialchars(json_encode($log->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $srcJson = htmlspecialchars(json_encode($state['source_table'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $viewJson = htmlspecialchars(json_encode($state['derived_view'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CDC Pipeline · Demo</title>
<style>
body{max-width:920px;margin:32px auto;padding:0 16px;background:#0d1117;color:#c9d1d9;font-family:system-ui,"Microsoft JhengHei",sans-serif;line-height:1.6}
h1,h2{color:#e6edf3} a{color:#58a6ff}
code,pre{background:#161b22;border:1px solid #30363d;border-radius:6px}
code{padding:2px 6px} pre{padding:12px;overflow:auto;max-height:320px}
.cols{display:flex;gap:16px;flex-wrap:wrap} .cols>div{flex:1;min-width:280px}
.box{border-left:4px solid #58a6ff;background:#161b22;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.label{font-weight:700;display:block;margin-bottom:4px}
button{background:#238636;color:#fff;border:0;padding:8px 14px;border-radius:6px;cursor:pointer;margin:2px}
button.alt{background:#1f6feb} button.warn{background:#9e6a03}
input,select{background:#0d1117;color:#c9d1d9;border:1px solid #30363d;border-radius:6px;padding:6px;margin:2px}
.status{font-size:1.1em;font-weight:700;margin:1em 0}
.hint{color:#8b949e;font-size:.88em}
table{width:100%;border-collapse:collapse;margin:.5em 0}
td,th{border:1px solid #30363d;padding:6px 8px;text-align:left;font-size:.9em}
</style>
</head><body>
<h1>🔄 CDC Data Pipeline · Demo</h1>
<p>對<strong>來源表</strong>做寫入 → 產生<strong>變更事件</strong>（含 op / before / after / 遞增 LSN）→
下游消費者<strong>依序冪等套用</strong>到衍生視圖（去正規化快取）→ 兩邊<strong>最終一致</strong>。</p>

<div class="status">一致性狀態：$badge　<span class="hint">offset={$state['consumer_offset']} / head_lsn={$state['head_lsn']}</span></div>

<div class="box"><span class="label">① 對來源表寫入（產生變更事件）</span>
  <div>
    操作
    <select id="op">
      <option value="c">c 建立 insert</option>
      <option value="u">u 更新 update</option>
      <option value="d">d 刪除 delete</option>
    </select>
    id <input id="id" type="number" value="1" style="width:70px">
    name <input id="name" value="Widget" style="width:120px">
    price <input id="price" type="number" value="100" style="width:90px">
    <button onclick="mutate()">送出寫入</button>
  </div>
  <p class="hint">delete 只需 id；建立/更新會帶 name、price 欄位。試試：建 id=1 → 改 id=1 的 price → 刪 id=1。</p>
</div>

<div class="box"><span class="label">② 下游消費</span>
  <button class="alt" onclick="consume('incremental')">下游套用（增量：拉 LSN&gt;offset）</button>
  <button class="warn" onclick="consume('replay')">重放全部（驗證冪等）</button>
  <p class="hint">「重放全部」會把所有事件再餵一次。因為有 LSN 去重，已套用的會被 skip，結果<strong>完全不變</strong>——這就是冪等。</p>
</div>

<button onclick="refresh()">🔃 重新整理現況</button>

<div class="cols">
  <div>
    <h2>變更日誌（binlog/topic）</h2>
    <pre id="changes">$changesJson</pre>
  </div>
</div>
<div class="cols">
  <div>
    <h2>來源表 source</h2>
    <pre id="src">$srcJson</pre>
  </div>
  <div>
    <h2>衍生視圖 derived（含 _lsn 戳記）</h2>
    <pre id="view">$viewJson</pre>
  </div>
</div>

<p class="hint">※ 誠實聲明：本 demo 用 JSON 檔模擬 binlog；<strong>變更擷取（before/after/op）、LSN 有序、依序冪等套用</strong>等系統邏輯為真實可執行。</p>

<script>
async function mutate(){
  const op=document.getElementById('op').value;
  const id=parseInt(document.getElementById('id').value,10);
  const fields=(op==='d')?{}:{name:document.getElementById('name').value,price:parseFloat(document.getElementById('price').value)};
  const r=await fetch('/api/mutate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({op,id,fields})});
  const j=await r.json();
  if(!r.ok){alert(j.error||'錯誤');return;}
  await refresh();
}
async function consume(mode){
  const r=await fetch('/api/consume',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({mode})});
  const j=await r.json();
  alert('mode='+mode+'\\napplied='+j.applied+'　skipped(去重)='+j.skipped+'\\nnew_offset='+j.new_offset);
  await refresh();
}
async function refresh(){
  const [c,s]=await Promise.all([fetch('/api/changes').then(r=>r.json()),fetch('/api/state').then(r=>r.json())]);
  document.getElementById('changes').textContent=JSON.stringify(c.changes,null,2);
  document.getElementById('src').textContent=JSON.stringify(s.source_table,null,2);
  document.getElementById('view').textContent=JSON.stringify(s.derived_view,null,2);
  location.reload();
}
</script>
</body></html>
HTML;
}
