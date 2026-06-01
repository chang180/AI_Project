<?php
declare(strict_types=1);

/**
 * CDN Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8051 -t public
 *
 * 路由：
 *   GET  /                       測試頁（從某區域請求資產看 hit/miss、purge、看各邊緣命中率）
 *   GET  /api/fetch?asset=&region=   從指定區域請求資產 → 回 hit/miss + 是否回源
 *   POST /api/purge              { asset } 失效所有邊緣上的該資產 → 下次必 miss
 *   GET  /api/stats              各區命中/未命中、總命中率、回源比、回源次數
 */

require __DIR__ . '/../src/Cdn.php';

$cdn = new Cdn(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- GET /api/fetch?asset=&region= ----
if ($method === 'GET' && $path === '/api/fetch') {
    $asset = trim((string) ($_GET['asset'] ?? ''));
    $region = trim((string) ($_GET['region'] ?? 'tw'));
    if ($asset === '') {
        http_response_code(400);
        exit(json_out(['error' => 'asset 必填']));
    }
    $r = $cdn->fetch($asset, $region);
    if (!$r['found']) {
        http_response_code(404);
        exit(json_out([
            'error'  => 'origin 也沒有這個資產',
            'asset'  => $asset,
            'region' => $r['region'],
        ]));
    }
    exit(json_out([
        'asset'   => $r['asset'],
        'region'  => $r['region'],
        'result'  => $r['hit'] ? 'HIT' : 'MISS',
        'served_from' => $r['served'],          // edge | origin
        'ring_node'   => $r['node'],            // 一致性雜湊算出的歸屬節點
        'ttl_on_miss' => $r['ttl'],             // miss 回源時，依 Cache-Control 存的 TTL（秒）
        'body'    => $r['body'],
    ]));
}

// ---- POST /api/purge ----
if ($method === 'POST' && $path === '/api/purge') {
    $asset = trim((string) ($_POST['asset'] ?? ''));
    if ($asset === '' && ($raw = file_get_contents('php://input'))) {
        $asset = trim((string) (json_decode($raw, true)['asset'] ?? ''));
    }
    if ($asset === '') {
        http_response_code(400);
        exit(json_out(['error' => 'asset 必填']));
    }
    $removed = $cdn->purge($asset);
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        header('Location: /?purged=' . rawurlencode($asset));
        exit;
    }
    exit(json_out([
        'purged'        => $asset,
        'edges_cleared' => $removed,   // 清掉的（區域）筆數
        'note'          => '下次任何區域請求此資產都會 MISS → 回源拿最新版本',
    ]));
}

// ---- GET /api/stats ----
if ($method === 'GET' && $path === '/api/stats') {
    exit(json_out($cdn->stats()));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($cdn);
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

function render_home(Cdn $cdn): void
{
    $purged = $_GET['purged'] ?? null;
    $assets = $cdn->origin()->list();
    $regions = Cdn::regions();
    $stats = $cdn->stats();
    $edge = $cdn->edgeState();

    // 資產下拉
    $assetOpts = '';
    foreach ($assets as $a) {
        $h = htmlspecialchars($a);
        $assetOpts .= "<option value=\"$h\">$h</option>";
    }
    // 區域下拉
    $regionOpts = '';
    foreach ($regions as $r) {
        $regionOpts .= "<option value=\"$r\">$r</option>";
    }

    // 各區命中率表
    $statRows = '';
    foreach ($stats['per_region'] as $region => $s) {
        $rate = number_format($s['hit_rate'] * 100, 1);
        $statRows .= "<tr><td><code>$region</code></td><td>{$s['hit']}</td><td>{$s['miss']}</td>"
            . "<td>{$s['requests']}</td><td>{$rate}%</td></tr>";
    }

    // 各邊緣目前快取了哪些資產（剩餘 TTL）
    $edgeRows = '';
    foreach ($edge as $region => $cached) {
        $items = '';
        foreach ($cached as $asset => $remain) {
            $ha = htmlspecialchars($asset);
            $items .= "<div><code>$ha</code> <span style='color:#7ee787'>剩 {$remain}s</span></div>";
        }
        if ($items === '') {
            $items = "<span style='color:#888'>（空）</span>";
        }
        $edgeRows .= "<tr><td><code>$region</code></td><td>$items</td></tr>";
    }

    $totalRate = number_format($stats['hit_rate'] * 100, 1);
    $originRatio = number_format($stats['origin_ratio'] * 100, 1);
    $originFetches = $stats['origin_fetches'];

    $banner = $purged
        ? "<div class='box tip'><span class='label'>已 purge：" . htmlspecialchars((string) $purged)
          . "</span>所有邊緣已清除此資產，下次請求必 MISS → 回源。</div>"
        : '';

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CDN · Demo</title>
<style>
  body{max-width:860px;margin:40px auto;padding:0 16px;
    font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#161616;color:#e8e8e8}
  h1,h2{color:#fff} a{color:#7ee787}
  code{background:#222;padding:2px 6px;border-radius:4px}
  .box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
  .box .label{font-weight:700;display:block}
  table{width:100%;border-collapse:collapse;margin:.6em 0}
  td,th{border:1px solid #444;padding:8px;text-align:left;vertical-align:top}
  button{background:#2f9e44;color:#fff;border:0;padding:8px 16px;border-radius:6px;cursor:pointer}
  button.alt{background:#1971c2}
  select,input{background:#222;color:#eee;border:1px solid #444;border-radius:6px;padding:7px 8px}
  .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:.6em 0}
  #out{background:#0d0d0d;border:1px solid #333;border-radius:8px;padding:12px;white-space:pre-wrap;
    font-family:ui-monospace,monospace;font-size:.85rem;min-height:2em}
  .pill{display:inline-block;padding:2px 10px;border-radius:999px;font-weight:700}
  .hit{background:#143b1f;color:#7ee787} .miss{background:#3b1414;color:#ff8a8a}
</style></head><body>
<h1>🌐 CDN · Demo</h1>
<p>從某區域請求資產 → 看 <b>HIT/MISS</b>（miss 會回源 origin 並依 Cache-Control TTL 存到邊緣）；
<b>purge</b> 後再請求變 MISS；下方看各邊緣命中率與目前快取內容。</p>
$banner

<h2>① 從某區域請求資產</h2>
<div class="row">
  資產 <select id="asset">$assetOpts</select>
  區域 <select id="region">$regionOpts</select>
  <button onclick="doFetch()">請求 (GET /api/fetch)</button>
</div>

<h2>② 快取失效 (purge)</h2>
<div class="row">
  資產 <select id="purgeAsset">$assetOpts</select>
  <button class="alt" onclick="doPurge()">purge (POST /api/purge)</button>
</div>

<h3>結果</h3>
<div id="out">（操作後顯示）</div>

<h2>③ 各邊緣命中率（GET /api/stats）</h2>
<p>總命中率 <b>{$totalRate}%</b>　回源比 <b>{$originRatio}%</b>　實際回源次數 <b>{$originFetches}</b></p>
<table><tr><th>區域</th><th>hit</th><th>miss</th><th>requests</th><th>命中率</th></tr>$statRows</table>

<h2>④ 目前各邊緣快取內容（剩餘 TTL）</h2>
<table><tr><th>邊緣 PoP</th><th>已快取資產</th></tr>$edgeRows</table>

<p style="color:#888;font-size:.85rem">※ 邊緣節點為記憶體模擬；hit/miss、回源、TTL 過期、purge、就近路由皆為真實邏輯。</p>

<script>
async function doFetch(){
  const a=document.getElementById('asset').value, r=document.getElementById('region').value;
  const res=await fetch('/api/fetch?asset='+encodeURIComponent(a)+'&region='+encodeURIComponent(r));
  const j=await res.json(); show(j); setTimeout(()=>location.reload(),700);
}
async function doPurge(){
  const a=document.getElementById('purgeAsset').value;
  const res=await fetch('/api/purge',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({asset:a})});
  const j=await res.json(); show(j); setTimeout(()=>location.reload(),700);
}
function show(j){
  let head='';
  if(j.result){const c=j.result==='HIT'?'hit':'miss';head="<span class='pill "+c+"'>"+j.result+"</span> 由 "+j.served_from+" 提供\\n\\n";}
  document.getElementById('out').innerHTML=head+JSON.stringify(j,null,2);
}
</script>
</body></html>
HTML;
}
