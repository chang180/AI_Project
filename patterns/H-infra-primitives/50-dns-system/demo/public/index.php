<?php
declare(strict_types=1);

/**
 * DNS 系統 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8050 -t public
 *
 * 路由：
 *   GET  /                                       測試頁（輸入網域解析、看解析鏈/快取、加記錄、Geo 解析）
 *   POST /api/record                             新增記錄 { name, type(A|CNAME), value, ttl, geo:{tw,us,eu} }
 *   GET  /api/resolve?name=&client_region=       解析網域 → 回完整解析鏈 + 是否命中快取
 *   GET  /api/state                              目前所有區域 + 快取狀態
 *   POST /api/flush                              清空快取（示範清掉後又要走完整遞迴）
 */

require __DIR__ . '/../src/Resolver.php';

$resolver = new Resolver(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- GET /api/resolve：解析（核心）----
if ($method === 'GET' && $path === '/api/resolve') {
    $name = trim((string) ($_GET['name'] ?? ''));
    $region = trim((string) ($_GET['client_region'] ?? 'tw'));
    if ($name === '') {
        http_response_code(400);
        exit(json_out(['ok' => false, 'error' => 'name 必填']));
    }
    $res = $resolver->resolve($name, $region !== '' ? $region : 'tw');
    exit(json_out(['ok' => true, 'result' => $res]));
}

// ---- POST /api/record：新增記錄 ----
if ($method === 'POST' && $path === '/api/record') {
    $body  = read_body();
    $name  = (string) ($body['name'] ?? '');
    $type  = (string) ($body['type'] ?? 'A');
    $value = (string) ($body['value'] ?? '');
    $ttl   = (int) ($body['ttl'] ?? 300);
    $geo   = is_array($body['geo'] ?? null) ? $body['geo'] : [];
    // 清掉空白的 geo 欄位
    $geo = array_filter($geo, static fn($v) => is_string($v) && trim($v) !== '');
    if ($name === '') {
        http_response_code(400);
        exit(json_out(['ok' => false, 'error' => 'name 必填']));
    }
    $r = $resolver->addRecord($name, $type, $value, $ttl, $geo);
    if (!($r['ok'] ?? false)) {
        http_response_code(400);
    }
    exit(json_out($r));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out($resolver->state()));
}

// ---- POST /api/flush：清快取 ----
if ($method === 'POST' && $path === '/api/flush') {
    $resolver->flushCache();
    exit(json_out(['ok' => true, 'message' => '快取已清空']));
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
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
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
<title>DNS 系統 · Demo</title>
<style>
:root{--bg:#0f1419;--card:#1e2630;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--ok:#7ee787;--warn:#f0883e;--danger:#ff7b72;--code:#161b22}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.6;max-width:860px;margin:0 auto;padding:32px 20px 80px}
h1{font-size:1.6rem}h2{font-size:1.15rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin-top:1.8em}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin:1em 0}
label{display:inline-block;color:var(--dim);font-size:.9rem;margin-right:6px}
input,select{background:var(--code);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:6px 10px;font-size:.95rem}
button{background:var(--accent);color:#04121f;border:none;border-radius:6px;padding:8px 16px;font-weight:700;cursor:pointer;margin-right:8px}
button.ghost{background:var(--code);color:var(--text);border:1px solid var(--border)}
code{background:var(--code);color:var(--ok);padding:2px 6px;border-radius:6px;font-size:.88em}
.row{margin:.6em 0}
#log,#state{font-family:ui-monospace,Consolas,monospace;font-size:.85rem;background:var(--code);border:1px solid var(--border);border-radius:8px;padding:12px;overflow:auto;white-space:pre-wrap}
#log{height:300px}#state{max-height:260px}
.pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:.8rem;font-weight:700}
.pill.ok{background:rgba(126,231,135,.15);color:var(--ok)}
.pill.no{background:rgba(255,123,114,.15);color:var(--danger)}
.pill.hit{background:rgba(88,166,255,.18);color:var(--accent)}
.note{color:var(--dim);font-size:.82rem}
.warnbox{border-left:4px solid var(--warn);background:#1a2027;padding:10px 14px;border-radius:0 8px 8px 0;margin:1em 0;font-size:.85rem;color:var(--dim)}
.warnbox b{color:var(--text)}
.chain{margin:0;padding-left:0;list-style:none}
.chain li{padding:3px 0;border-bottom:1px dashed var(--border)}
.chain .s{color:var(--accent);font-weight:700}
</style></head><body>
<h1>🌐 DNS 系統 · Demo</h1>
<p class="note">把網域名翻成 IP：<code>stub → recursive resolver → root → TLD → authoritative</code> 三層遞迴 +
解析快取（含 TTL）+ A/CNAME + GeoDNS（依區域回最近 IP）。狀態用 JSON + flock 原子更新。</p>

<div class="card">
  <h2 style="margin-top:0">① 解析網域</h2>
  <div class="row">
    <label>網域</label><input id="name" value="www.example.com" style="width:220px">
    <label style="margin-left:10px">client 區域</label>
    <select id="region">
      <option value="tw">tw 台灣</option>
      <option value="us">us 美國</option>
      <option value="eu">eu 歐洲</option>
    </select>
    <button onclick="doResolve()">解析</button>
    <button class="ghost" onclick="flushCache()">清空快取</button>
  </div>
  <p class="note">技巧：先解析一次（走完整遞迴）→ 立刻再解析同一個（命中快取，省去遞迴）。
  把區域切成 <code>us</code>／<code>eu</code> 再解析 <code>www.example.com</code> → GeoDNS 回不同 IP。
  試 <code>blog.example.com</code> 看 CNAME 再跟一次。</p>
  <div id="log"></div>
</div>

<div class="card">
  <h2 style="margin-top:0">② 新增記錄</h2>
  <div class="row">
    <label>網域</label><input id="rname" value="shop.example.com" style="width:200px">
    <label>型別</label>
    <select id="rtype" onchange="onType()">
      <option value="A">A（IP）</option>
      <option value="CNAME">CNAME（別名）</option>
    </select>
    <label>TTL</label><input id="rttl" type="number" value="120" min="1" style="width:80px">
  </div>
  <div class="row" id="valrow">
    <label>值（A=IP / CNAME=目標網域）</label><input id="rval" value="198.51.100.99" style="width:260px">
  </div>
  <div class="row" id="georow">
    <label>GeoDNS（可選，留空=非 Geo）</label>
    tw <input id="gtw" placeholder="1.1.1.1" style="width:120px">
    us <input id="gus" placeholder="2.2.2.2" style="width:120px">
    eu <input id="geu" placeholder="3.3.3.3" style="width:120px">
  </div>
  <div class="row"><button onclick="addRecord()">新增 / 更新記錄</button>
    <span class="note">新增後相關快取會被失效，下次解析會走完整遞迴並讀到新值。</span></div>
</div>

<div class="card">
  <h2 style="margin-top:0">③ 目前區域 + 快取狀態</h2>
  <div class="row"><button class="ghost" onclick="loadState()">重新整理狀態</button></div>
  <div id="state"></div>
</div>

<div class="warnbox">
  <b>誠實聲明：</b>本 demo 用記憶體 + JSON 檔模擬 <b>root / TLD / authoritative 三層區域表</b>，沒有真正的網路 DNS 封包。
  但 <b>階層遞迴解析（回傳完整解析鏈）、解析快取與 TTL、A/CNAME 再跟一次、GeoDNS 依區域回不同 IP</b> 等 DNS 邏輯都是真實可執行的。
</div>

<script>
const logEl = document.getElementById('log');
function log(msg){ logEl.innerHTML = msg + "<hr style='border:0;border-top:1px solid #2a3540;margin:6px 0'>" + logEl.innerHTML; }

function onType(){
  const isC = document.getElementById('rtype').value === 'CNAME';
  document.getElementById('georow').style.display = isC ? 'none' : 'block';
}

async function doResolve(){
  const name = document.getElementById('name').value.trim();
  const region = document.getElementById('region').value;
  const t = new Date().toLocaleTimeString();
  const r = await fetch('/api/resolve?name=' + encodeURIComponent(name) + '&client_region=' + region);
  const j = await r.json();
  if (!j.ok){ log(t + '  ❌ ' + (j.error||'錯誤')); return; }
  const res = j.result;
  const hit = res.cache_hit
    ? '<span class="pill hit">快取命中</span>'
    : '<span class="pill ok">完整遞迴</span>';
  const geo = res.geo ? ' <span class="pill hit">GeoDNS</span>' : '';
  let html = t + '  解析 <code>' + name + '</code>（區域 ' + region + '） ' + hit + geo + '<br>';
  html += '答案：<b style="color:#7ee787">' + (res.answer ?? '(查無)') + '</b>　型別 ' + res.type + '　TTL ' + res.ttl + 's<br>';
  html += '<ul class="chain">';
  res.chain.forEach((c,i)=>{ html += '<li><span class="s">' + (i+1) + '. ' + c.step + '</span>：' + c.detail + '</li>'; });
  html += '</ul>';
  log(html);
  loadState();
}

async function flushCache(){
  await fetch('/api/flush', {method:'POST'});
  log('🧹 已清空快取 → 下次解析會重新走完整遞迴');
  loadState();
}

async function addRecord(){
  const type = document.getElementById('rtype').value;
  const body = {
    name: document.getElementById('rname').value.trim(),
    type: type,
    value: document.getElementById('rval').value.trim(),
    ttl: +document.getElementById('rttl').value
  };
  if (type === 'A'){
    const geo = {};
    if (gtw.value.trim()) geo.tw = gtw.value.trim();
    if (gus.value.trim()) geo.us = gus.value.trim();
    if (geu.value.trim()) geo.eu = geu.value.trim();
    if (Object.keys(geo).length){ geo.default = geo.tw || geo.us || geo.eu; body.geo = geo; }
  }
  const r = await fetch('/api/record', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
  const j = await r.json();
  log(j.ok ? ('✅ 已新增記錄 ' + body.name + '：' + JSON.stringify(j.record)) : ('❌ ' + (j.error||'失敗')));
  loadState();
}

async function loadState(){
  const r = await fetch('/api/state');
  const j = await r.json();
  document.getElementById('state').textContent = JSON.stringify(j, null, 2);
}

onType();
loadState();
</script>
</body></html>
HTML;
}
