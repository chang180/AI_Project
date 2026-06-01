<?php
declare(strict_types=1);

/**
 * MapReduce / Word Count Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8053 -t public
 *
 * 路由：
 *   GET  /            測試頁：貼一段文字 → 跑 job → 看 map 輸出 / shuffle 分組 / reduce 結果三階段
 *   POST /api/job     跑一個 word count job  { text, mappers, reducers, combiner }
 *   GET  /api/state   讀回上次 job 的完整三階段中間產物
 */

require __DIR__ . '/../src/Job.php';

$job = new Job(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/job：跑一個 job ----
if ($method === 'POST' && $path === '/api/job') {
    $in = read_input();
    $text = trim((string) ($in['text'] ?? ''));
    if ($text === '') {
        http_response_code(400);
        exit(json_out(['error' => 'text 不可為空']));
    }
    $mappers = (int) ($in['mappers'] ?? 3);
    $reducers = (int) ($in['reducers'] ?? 2);
    $combiner = filter_var($in['combiner'] ?? false, FILTER_VALIDATE_BOOL);

    $state = $job->run($text, $mappers, $reducers, $combiner);

    // 表單送出（HTML）→ 導回首頁看結果；API 呼叫 → 回 JSON
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        header('Location: /?ran=1');
        exit;
    }
    exit(json_out($state));
}

// ---- GET /api/state：上次 job 狀態 ----
if ($method === 'GET' && $path === '/api/state') {
    $state = $job->last();
    if ($state === null) {
        http_response_code(404);
        exit(json_out(['error' => '尚未跑過任何 job']));
    }
    exit(json_out($state));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($job);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============
function read_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = (string) file_get_contents('php://input');
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return [];
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function render_home(Job $job): void
{
    $sample = "the quick brown fox\njumps over the lazy dog\nthe dog barks the fox runs\nquick fox quick dog";
    $state = $job->last();

    $stagesHtml = $state ? render_stages($state) : '<p style="color:#888">還沒有結果。貼一段文字、按「跑 MapReduce Job」就會看到三階段。</p>';

    $defaultText = $state['input'] ?? $sample;

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MapReduce Word Count · Demo</title>
<style>
body{max-width:980px;margin:32px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;color:#e6e6e6;background:#161616}
h1,h2,h3{color:#fff}
a{color:#7ee787}
textarea{width:100%;box-sizing:border-box;background:#1f1f1f;color:#eee;border:1px solid #444;border-radius:6px;padding:10px;font-family:ui-monospace,monospace}
.row{display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin:10px 0}
.row label{font-size:.9rem}
input[type=number]{width:64px;background:#1f1f1f;color:#eee;border:1px solid #444;border-radius:6px;padding:4px 6px}
button{background:#2f9e44;color:#fff;border:0;border-radius:6px;padding:9px 18px;font-size:1rem;cursor:pointer}
.box{border-left:4px solid #f2b705;background:#1f1d17;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
.stage{border:1px solid #333;border-radius:10px;padding:14px 16px;margin:14px 0;background:#1c1c1c}
.stage h3{margin:0 0 10px}
.grid{display:flex;gap:12px;flex-wrap:wrap}
.card{flex:1;min-width:200px;background:#202020;border:1px solid #383838;border-radius:8px;padding:10px 12px}
.card .head{font-weight:700;color:#9ad;margin-bottom:6px;font-size:.9rem}
code{background:#2a2a2a;padding:1px 5px;border-radius:4px}
.pair{display:inline-block;background:#262626;border:1px solid #3a3a3a;border-radius:4px;padding:1px 6px;margin:2px;font-family:ui-monospace,monospace;font-size:.82rem}
table{width:100%;border-collapse:collapse;margin-top:6px}
td,th{border:1px solid #3a3a3a;padding:5px 8px;text-align:left;font-size:.88rem}
.arrow{text-align:center;color:#f2b705;font-size:1.4rem;margin:2px}
.summary span{display:inline-block;background:#222;border:1px solid #3a3a3a;border-radius:999px;padding:3px 10px;margin:2px;font-size:.82rem}
</style>
</head><body>
<h1>🧮 MapReduce Word Count · Demo</h1>
<p>貼一段英文/中文文字，按下去就會跑一輪 MapReduce，攤開三階段中間產物：
<strong>split → map（每詞輸出 (詞,1)）→ shuffle（依詞分組、partition 給 reducer）→ reduce（加總）</strong>。</p>

<form method="post" action="/api/job">
  <textarea name="text" rows="5" placeholder="貼一段文字...">{$defaultText}</textarea>
  <div class="row">
    <label>map 任務數 <input type="number" name="mappers" value="3" min="1" max="16"></label>
    <label>reduce 任務數 (R) <input type="number" name="reducers" value="2" min="1" max="8"></label>
    <label><input type="checkbox" name="combiner" value="1"> 啟用 combiner（map 端本地預聚合）</label>
    <button type="submit">跑 MapReduce Job ▶</button>
  </div>
</form>

<div class="box"><span class="label">怎麼看？</span>
① 輸入被切成幾片（split）→ ② 每片一個 map task 各吐 (詞,1) → ③ shuffle 依
<code>partition = hash(詞) % R</code> 把<strong>同一個詞匯到同一個 reducer</strong> →
④ 每個 reducer 把同詞加總。改 R 看分組怎麼變；開 combiner 看中間對數量變少。</div>

$stagesHtml

<p style="color:#888;font-size:.85rem;margin-top:2em">※ 單機模擬多個 map/reduce worker；map / shuffle / reduce 三階段與 partition 規則為<strong>真實邏輯</strong>。
另可程式化呼叫 <code>POST /api/job</code>、<code>GET /api/state</code>（回 JSON）。</p>
</body></html>
HTML;
}

function render_stages(array $state): string
{
    $sum = $state['summary'];
    $out = '<div class="stage summary"><h3>本次 Job 摘要</h3>'
        . '<span>map 任務：' . (int) $sum['numMappers'] . '</span>'
        . '<span>reduce 任務 (R)：' . (int) $sum['numReducers'] . '</span>'
        . '<span>combiner：' . ($sum['combiner'] ? '開' : '關') . '</span>'
        . '<span>中間 (詞,值) 對數：' . (int) $sum['intermediatePairs'] . '</span>'
        . '<span>總詞數：' . (int) $sum['totalTokens'] . '</span>'
        . '<span>相異詞：' . (int) $sum['distinctWords'] . '</span></div>';

    // 階段 1：split + map
    $out .= '<div class="stage"><h3>① split → map：每個 map task 吃一片，輸出 (詞, 1)</h3><div class="grid">';
    foreach ($state['mapOutputs'] as $i => $pairs) {
        $lines = $state['splits'][$i] ?? [];
        $out .= '<div class="card"><div class="head">map task #' . (int) $i
            . '　（' . count($lines) . ' 行 → ' . count($pairs) . ' 對）</div>';
        $out .= '<div style="color:#888;font-size:.8rem;margin-bottom:6px">輸入片：' . h(implode(' / ', $lines)) . '</div>';
        foreach ($pairs as [$w, $c]) {
            $out .= '<span class="pair">(' . h((string) $w) . ', ' . (int) $c . ')</span>';
        }
        $out .= '</div>';
    }
    $out .= '</div></div>';

    $out .= '<div class="arrow">▼ shuffle / sort（依詞分組，partition = hash(詞) % R）</div>';

    // 階段 2：shuffle 分組
    $out .= '<div class="stage"><h3>② shuffle：同一個詞匯到同一個 reducer</h3><div class="grid">';
    foreach ($state['partitions'] as $r => $groups) {
        $out .= '<div class="card"><div class="head">reducer #' . (int) $r . ' 收到 ' . count($groups) . ' 個詞</div>';
        foreach ($groups as $word => $values) {
            $out .= '<span class="pair">' . h((string) $word) . ' → ['
                . h(implode(',', array_map('strval', $values))) . ']</span>';
        }
        $out .= '</div>';
    }
    $out .= '</div></div>';

    $out .= '<div class="arrow">▼ reduce（每詞加總）</div>';

    // 階段 3：reduce 結果
    $out .= '<div class="stage"><h3>③ reduce：每個 reducer 把同詞 value 加總</h3><div class="grid">';
    foreach ($state['reduceOutputs'] as $r => $reduced) {
        $out .= '<div class="card"><div class="head">reducer #' . (int) $r . ' 輸出</div>';
        foreach ($reduced as $word => $cnt) {
            $out .= '<span class="pair">(' . h((string) $word) . ', ' . (int) $cnt . ')</span>';
        }
        $out .= '</div>';
    }
    $out .= '</div></div>';

    // 合併最終結果
    $out .= '<div class="stage"><h3>✅ 合併最終 word count（依次數排序）</h3><table><tr><th>詞</th><th>次數</th></tr>';
    foreach ($state['result'] as $word => $cnt) {
        $out .= '<tr><td><code>' . h((string) $word) . '</code></td><td>' . (int) $cnt . '</td></tr>';
    }
    $out .= '</table></div>';

    return $out;
}
