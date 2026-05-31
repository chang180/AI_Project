<?php
declare(strict_types=1);

/**
 * Typeahead 自動完成 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8016 -t public
 *
 * 路由：
 *   GET  /                          深色測試頁（輸入框即時顯示 Top-K 建議）
 *   GET  /api/suggest?q=&limit=     前綴查詢，回 Top-K（讀，超高頻關鍵路徑）
 *   POST /api/select                被選中事件：{ term } → 頻率 +1（寫，更新排名）
 *   POST /api/reset                 重設詞頻為種子
 *
 * 核心：每個請求載入詞頻 → 重建 Trie → 在節點預存 Top-K → O(前綴長度) 回應。
 */

require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Suggester.php';

$store = new Store(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- GET /api/suggest：前綴查詢，回 Top-K ----
if ($method === 'GET' && $path === '/api/suggest') {
    $q = (string) ($_GET['q'] ?? '');
    $limit = max(1, min(20, (int) ($_GET['limit'] ?? 10)));
    $sug = new Suggester($limit);
    $sug->load($store->all());                 // 重建 Trie + 預存 Top-K
    exit(json_out([
        'prefix' => $q,
        'suggestions' => $sug->suggest($q),     // O(前綴長度) 讀出節點預存 Top-K
    ]));
}

// ---- POST /api/select：被選中 → 頻率 +1 ----
if ($method === 'POST' && $path === '/api/select') {
    $term = read_term();
    if ($term === '') {
        http_response_code(400);
        exit(json_out(['error' => 'term 不可為空']));
    }
    $sug = new Suggester();
    $sug->load($store->all());
    $newFreq = $sug->select($term);             // 記憶體中頻率 +1 並重算受影響節點 Top-K
    if ($newFreq === null) {
        http_response_code(404);
        exit(json_out(['error' => "詞不存在：$term"]));
    }
    $store->setFreq($term, $newFreq);           // 寫回，使下次請求重建時生效（示意離線聚合）
    exit(json_out(['term' => $term, 'freq' => $newFreq]));
}

// ---- POST /api/reset：重設詞頻 ----
if ($method === 'POST' && $path === '/api/reset') {
    $store->reset();
    exit(json_out(['ok' => true]));
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

function read_term(): string
{
    $term = trim((string) ($_POST['term'] ?? ''));
    if ($term === '' && ($raw = file_get_contents('php://input'))) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $term = trim((string) ($decoded['term'] ?? ''));
        }
    }
    return $term;
}

function render_home(): void
{
    echo <<<'HTML'
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Typeahead 自動完成 · Demo</title>
<style>
  :root{--bg:#0f1419;--card:#1e2630;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--accent2:#7ee787;}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.7}
  .wrap{max-width:760px;margin:0 auto;padding:40px 24px 80px}
  h1{font-size:1.8rem;margin:.2em 0}
  p.sub{color:var(--dim);margin-top:0}
  .search{position:relative;margin:1.5em 0}
  #q{width:100%;font-size:1.2rem;padding:14px 16px;border-radius:10px;border:1px solid var(--border);background:var(--card);color:var(--text)}
  #q:focus{outline:none;border-color:var(--accent)}
  ul.sug{list-style:none;margin:.4em 0 0;padding:0;border:1px solid var(--border);border-radius:10px;overflow:hidden}
  ul.sug:empty{display:none}
  ul.sug li{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;cursor:pointer;border-top:1px solid var(--border)}
  ul.sug li:first-child{border-top:none}
  ul.sug li:hover{background:#26303c}
  ul.sug .term{color:var(--text)}
  ul.sug .freq{color:var(--accent2);font-size:.85rem}
  .box{border-left:4px solid var(--accent2);background:#1a2027;padding:12px 16px;border-radius:0 8px 8px 0;margin:1.5em 0;font-size:.92rem;color:var(--dim)}
  .box b{color:var(--text)}
  code{background:#161b22;color:var(--accent2);padding:2px 6px;border-radius:6px;font-size:.88em}
  button{background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:8px 14px;cursor:pointer}
  button:hover{border-color:var(--accent)}
  .hint{color:var(--dim);font-size:.85rem}
</style></head><body>
<div class="wrap">
  <h1>🔎 Typeahead 自動完成 · Demo</h1>
  <p class="sub">輸入前綴看 Top-K 建議 → 點選某建議使其頻率 +1 → 再查同前綴看排名變化。</p>

  <div class="search">
    <input id="q" type="text" placeholder="試試：自 / 系統 / sea / search" autocomplete="off" autofocus>
    <ul id="sug" class="sug"></ul>
  </div>

  <p class="hint">每次敲鍵打一次 <code>GET /api/suggest?q=前綴</code>；點選打 <code>POST /api/select</code> 讓該詞頻率上升。
    <button id="reset">重設詞頻</button></p>

  <div class="box">
    <b>核心邏輯</b>：伺服端每次請求載入詞頻 → 重建 Trie → 自底向上在每個節點預存 Top-K，
    查詢時沿前綴走 <b>O(前綴長度)</b> 步直接讀出節點的 Top-K（與候選詞數量無關）。
    這正是真實 Typeahead 服務「<b>每個前綴預先算好 Top-K</b>」的精髓。
  </div>

<script>
const q = document.getElementById('q'), sug = document.getElementById('sug');
let timer = null;
function escapeHtml(s){return s.replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
async function refresh(){
  const v = q.value.trim();
  if(!v){ sug.innerHTML=''; return; }
  const r = await fetch('/api/suggest?q='+encodeURIComponent(v)+'&limit=10');
  const data = await r.json();
  sug.innerHTML = (data.suggestions||[]).map(s =>
    `<li data-term="${escapeHtml(s.term)}"><span class="term">${escapeHtml(s.term)}</span><span class="freq">freq ${s.freq}</span></li>`
  ).join('');
}
q.addEventListener('input', ()=>{ clearTimeout(timer); timer=setTimeout(refresh,120); }); // 防抖 debounce
sug.addEventListener('click', async e=>{
  const li = e.target.closest('li'); if(!li) return;
  await fetch('/api/select',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({term:li.dataset.term})});
  refresh(); // 重新查詢，看排名變化
});
document.getElementById('reset').addEventListener('click', async ()=>{
  await fetch('/api/reset',{method:'POST'}); refresh();
});
</script>
</div>
</body></html>
HTML;
}
