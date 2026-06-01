<?php
declare(strict_types=1);

/**
 * 向量資料庫 / ANN 搜尋 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8052 -t public
 *
 * 路由：
 *   GET  /                       測試頁（加向量、kNN 查詢看「暴力 vs ANN」各掃幾個 + 結果差異）
 *   POST /api/vectors            新增向量 { vec:[..], label }
 *   GET  /api/search?q=&k=&mode=brute|ann&nprobe=   查詢
 *   GET  /api/state              目前向量數 / 維度 / IVF 群數
 */

require __DIR__ . '/../src/VectorStore.php';
require __DIR__ . '/../src/Similarity.php';
require __DIR__ . '/../src/VectorIndex.php';

$store = new VectorStore(__DIR__ . '/../data');

// 第一次啟動塞幾筆 4 維範例向量（語意分 3 群：水果 / 交通 / 天氣）
if ($store->count() === 0) {
    seed($store);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/vectors：新增向量 ----
if ($method === 'POST' && $path === '/api/vectors') {
    $in = read_input();
    $vec = parse_vec($in['vec'] ?? null);
    $label = trim((string) ($in['label'] ?? ''));
    if ($vec === null) {
        http_response_code(400);
        exit(json_out(['error' => 'vec 需為逗號分隔的數字，如 0.9,0.1,0,0']));
    }
    $dim = $store->dim();
    if ($dim > 0 && count($vec) !== $dim) {
        http_response_code(400);
        exit(json_out(['error' => "維度需為 $dim 維（與現有向量一致）"]));
    }
    $id = $store->add($vec, ['label' => $label !== '' ? $label : ('vec#' . ($store->count() + 1))]);
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        header('Location: /?added=' . $id);
        exit;
    }
    exit(json_out(['id' => $id, 'vec' => $vec]));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    $items = $store->all();
    $idx = new VectorIndex($items, 'cosine');
    exit(json_out([
        'count'  => count($items),
        'dim'    => $store->dim(),
        'metric' => 'cosine',
        'ivf_nlist' => $idx->nlist(),
    ]));
}

// ---- GET /api/search ----
if ($method === 'GET' && $path === '/api/search') {
    $q = parse_vec($_GET['q'] ?? null);
    $k = max(1, (int) ($_GET['k'] ?? 3));
    $mode = ($_GET['mode'] ?? 'brute') === 'ann' ? 'ann' : 'brute';
    $nprobe = max(1, (int) ($_GET['nprobe'] ?? 1));
    $metric = ($_GET['metric'] ?? 'cosine') === 'dot' ? 'dot' : 'cosine';
    if ($q === null) {
        http_response_code(400);
        exit(json_out(['error' => 'q 需為逗號分隔的查詢向量']));
    }
    $items = $store->all();
    $idx = new VectorIndex($items, $metric);

    $brute = $idx->bruteKnn($q, $k);
    if ($mode === 'ann') {
        $ann = $idx->annKnn($q, $k, $nprobe);
        exit(json_out([
            'mode'    => 'ann',
            'k'       => $k,
            'nprobe'  => $nprobe,
            'nlist'   => $ann['nlist'],
            'scanned' => $ann['scanned'],   // ANN 只掃 M 個
            'total_n' => count($items),     // 暴力會掃 N 個
            'recall'  => round(VectorIndex::recall($brute['results'], $ann['results']), 3),
            'results' => $ann['results'],
        ]));
    }
    exit(json_out([
        'mode'    => 'brute',
        'k'       => $k,
        'scanned' => $brute['scanned'],     // 掃 N 個
        'total_n' => count($items),
        'results' => $brute['results'],
    ]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($store);
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

/** 把 "0.9,0.1,0,0" 或陣列解析成 float[]，失敗回 null */
function parse_vec(mixed $in): ?array
{
    if (is_array($in)) {
        $vec = array_map('floatval', $in);
        return $vec ?: null;
    }
    $s = trim((string) $in);
    if ($s === '') {
        return null;
    }
    $parts = preg_split('/\s*,\s*/', $s);
    if (!$parts) {
        return null;
    }
    $vec = [];
    foreach ($parts as $p) {
        if (!is_numeric($p)) {
            return null;
        }
        $vec[] = (float) $p;
    }
    return $vec ?: null;
}

function seed(VectorStore $store): void
{
    // 4 維「假嵌入」，刻意分 3 個語意群（非真實模型，僅示意）
    $samples = [
        [[0.95, 0.10, 0.05, 0.00], '蘋果'],
        [[0.90, 0.20, 0.00, 0.05], '香蕉'],
        [[0.85, 0.05, 0.10, 0.00], '橘子'],
        [[0.05, 0.95, 0.05, 0.00], '汽車'],
        [[0.00, 0.90, 0.10, 0.05], '火車'],
        [[0.10, 0.88, 0.00, 0.02], '飛機'],
        [[0.05, 0.00, 0.95, 0.10], '下雨'],
        [[0.00, 0.05, 0.90, 0.20], '晴天'],
        [[0.10, 0.00, 0.85, 0.15], '颱風'],
    ];
    foreach ($samples as [$vec, $label]) {
        $store->add($vec, ['label' => $label]);
    }
}

function render_home(VectorStore $store): void
{
    $added = $_GET['added'] ?? null;
    $items = $store->all();
    $idx = new VectorIndex($items, 'cosine');
    $dim = $store->dim();

    $rows = '';
    foreach ($items as $it) {
        $v = implode(', ', array_map(static fn($x) => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.') ?: '0', $it['vec']));
        $rows .= '<tr><td>' . $it['id'] . '</td><td>' . htmlspecialchars((string) ($it['meta']['label'] ?? '')) . '</td><td><code>[' . $v . ']</code></td></tr>';
    }

    $banner = $added ? "<div class='box'><span class='label'>已新增向量 id=$added</span></div>" : '';
    $example = $items ? implode(',', $items[0]['vec']) : '0.9,0.1,0,0';

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>向量資料庫 / ANN 搜尋 · Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>body{max-width:820px;margin:40px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block}code{background:#222;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse}td,th{border:1px solid #444;padding:6px 8px;text-align:left}
input,select{padding:4px 6px}.col{display:flex;gap:18px;flex-wrap:wrap}.col>div{flex:1;min-width:300px}
#out{white-space:pre-wrap;background:#111;padding:12px;border-radius:8px;font-size:.85rem}</style>
</head><body>
<h1>🧭 向量資料庫 / ANN 搜尋 · Demo</h1>
<p>現有 <strong>{$store->count()}</strong> 筆向量、<strong>{$dim}</strong> 維、IVF 分成 <strong>{$idx->nlist()}</strong> 群。
用「語意相近」找最像的 k 筆，比較 <strong>暴力 kNN（掃全部 N）</strong> vs <strong>ANN（只掃近群 M）</strong>。</p>
$banner

<div class="col">
<div>
  <h2>① 新增向量（{$dim} 維）</h2>
  <form method="post" action="/api/vectors">
    <input name="vec" placeholder="$example" style="width:60%" required>
    <input name="label" placeholder="標籤(可選)" style="width:30%">
    <button type="submit">新增</button>
  </form>
</div>
<div>
  <h2>② kNN 查詢</h2>
  <div>
    查詢向量 q：<input id="q" value="$example" style="width:55%"><br>
    k=<input id="k" type="number" value="3" min="1" style="width:60px">
    nprobe=<input id="np" type="number" value="1" min="1" style="width:60px">
    <button onclick="run('brute')">暴力 kNN</button>
    <button onclick="run('ann')">ANN(IVF)</button>
    <button onclick="compare()">兩者比較</button>
  </div>
</div>
</div>

<h3>結果</h3>
<div id="out">（按上面的按鈕查詢）</div>

<h2>現有向量</h2>
<table><tr><th>id</th><th>標籤</th><th>向量</th></tr>$rows</table>
<p style="color:#888;font-size:.85rem">※ 向量為「假嵌入」（手動指定的小維度數字，非真實模型輸出）；相似度、暴力 vs ANN 掃描數、IVF 分群、召回率為真實邏輯。</p>

<script>
function url(mode){
  const q=document.getElementById('q').value, k=document.getElementById('k').value, np=document.getElementById('np').value;
  return '/api/search?q='+encodeURIComponent(q)+'&k='+k+'&mode='+mode+'&nprobe='+np;
}
async function run(mode){
  const r=await fetch(url(mode)); const j=await r.json();
  document.getElementById('out').textContent=JSON.stringify(j,null,2);
}
async function compare(){
  const b=await (await fetch(url('brute'))).json();
  const a=await (await fetch(url('ann'))).json();
  const fmt=x=>x.results.map(r=>r.meta.label+'('+r.score.toFixed(3)+')').join(', ');
  const txt='【暴力 kNN】掃描 '+b.scanned+' / N='+b.total_n+' 個\\n  '+fmt(b)+
    '\\n\\n【ANN(IVF)】只掃 '+a.scanned+' / N='+a.total_n+' 個（nlist='+a.nlist+', nprobe='+a.nprobe+'）\\n'+
    '  召回率 vs 暴力：'+a.recall+'\\n  '+fmt(a);
  document.getElementById('out').textContent=txt;
}
</script>
</body></html>
HTML;
}
