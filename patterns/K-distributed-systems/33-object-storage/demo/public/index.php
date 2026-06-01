<?php
declare(strict_types=1);

/**
 * 物件儲存 S3 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8033 -t public
 *
 * 路由：
 *   GET  /                 測試頁（建 bucket、multipart 上傳、看分片放置、製造節點失效後還原、預簽 URL）
 *   POST /api/upload       multipart：?op=init|part|complete
 *   GET  /api/object       下載/還原物件（?bucket=&key=，或帶 expires&sig 走預簽驗章）
 *   POST /api/node-fail    標記節點失效/恢復 { node, failed }
 *   GET  /api/presign      產生預簽 URL ?bucket=&key=&ttl=
 *   GET  /api/state        整體狀態（節點 block 數、失效節點、bucket、物件數）
 */

require __DIR__ . '/../src/ObjectStore.php';

$store = new ObjectStore(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- POST /api/upload：multipart（init / part / complete） ----
if ($method === 'POST' && $path === '/api/upload') {
    $op = (string) ($_GET['op'] ?? '');
    $in = read_body();
    try {
        if ($op === 'init') {
            $bucket = trim((string) ($in['bucket'] ?? 'my-bucket'));
            $key = trim((string) ($in['key'] ?? 'hello.txt'));
            $store->createBucket($bucket);
            $uploadId = $store->initUpload($bucket, $key);
            exit(json_out([
                'uploadId' => $uploadId,
                'bucket' => $bucket,
                'key' => $key,
                'note' => 'init 完成。接著對每個 part 呼叫 ?op=part 上傳，最後 ?op=complete 組裝。',
            ]));
        }
        if ($op === 'part') {
            $uploadId = (string) ($in['uploadId'] ?? '');
            $partNumber = (int) ($in['partNumber'] ?? 1);
            $content = (string) ($in['content'] ?? '');
            $md5 = $store->uploadPart($uploadId, $partNumber, $content);
            exit(json_out([
                'uploadId' => $uploadId,
                'partNumber' => $partNumber,
                'size' => strlen($content),
                'partETag' => $md5,
                'note' => 'part ETag = md5(part 內容)',
            ]));
        }
        if ($op === 'complete') {
            $uploadId = (string) ($in['uploadId'] ?? '');
            $strategy = strtoupper((string) ($in['strategy'] ?? 'EC'));
            if ($strategy !== 'EC' && $strategy !== 'REPLICA') {
                $strategy = 'EC';
            }
            $replicas = (int) ($in['replicas'] ?? 3);
            $manifest = $store->completeUpload($uploadId, $strategy, $replicas);
            exit(json_out([
                'manifest' => $manifest,
                'note' => $strategy === 'EC'
                    ? '抹除碼 k=' . $manifest['erasure']['k'] . ',m=' . $manifest['erasure']['m']
                        . '：' . ($manifest['erasure']['k'] + $manifest['erasure']['m']) . ' 分片分散放置，空間倍率 '
                        . round(($manifest['erasure']['k'] + $manifest['erasure']['m']) / $manifest['erasure']['k'], 2) . 'x'
                    : '副本 R=' . $manifest['replicas'] . '：整顆物件複製 ' . $manifest['replicas'] . ' 份，空間倍率 ' . $manifest['replicas'] . 'x',
            ]));
        }
        http_response_code(400);
        exit(json_out(['error' => '未知 op，需 init|part|complete']));
    } catch (Throwable $e) {
        http_response_code(400);
        exit(json_out(['error' => $e->getMessage()]));
    }
}

// ---- GET /api/object：下載 / 還原（可選預簽驗章） ----
if ($method === 'GET' && $path === '/api/object') {
    $bucket = (string) ($_GET['bucket'] ?? '');
    $key = (string) ($_GET['key'] ?? '');

    // 若帶 expires + sig → 走預簽驗章
    if (isset($_GET['expires'], $_GET['sig'])) {
        $v = $store->verifyPresign('GET', $bucket, $key, (int) $_GET['expires'], (string) $_GET['sig']);
        if (!$v['ok']) {
            http_response_code(403);
            exit(json_out(['error' => '預簽驗證失敗', 'reason' => $v['reason']]));
        }
    }

    $manifest = $store->getManifest($bucket, $key);
    if (!$manifest) {
        http_response_code(404);
        exit(json_out(['error' => '物件不存在']));
    }
    try {
        $r = $store->fetchObject($manifest);
    } catch (Throwable $e) {
        http_response_code(409);
        exit(json_out(['error' => $e->getMessage()]));
    }
    $okEtag = $manifest['crc32'] === sprintf('%08x', crc32($r['bytes']));
    exit(json_out([
        'bucket' => $bucket,
        'key' => $key,
        'size' => strlen($r['bytes']),
        'etag' => $manifest['etag'],
        'strategy' => $manifest['strategy'],
        'recovered' => $r['recovered'],
        'integrityOk' => $okEtag,
        'placementDetail' => $r['detail'],
        'content' => $r['bytes'],
        'note' => $r['recovered']
            ? '偵測到失效節點，已用' . ($manifest['strategy'] === 'EC' ? ' parity 還原' : '其他副本') . '；crc32 比對 ' . ($okEtag ? '一致 ✓' : '不一致 ✗')
            : '全部節點正常，直接讀取；crc32 比對 ' . ($okEtag ? '一致 ✓' : '不一致 ✗'),
    ]));
}

// ---- POST /api/node-fail：標記節點失效 / 恢復 ----
if ($method === 'POST' && $path === '/api/node-fail') {
    $in = read_body();
    $node = (string) ($in['node'] ?? '');
    $failed = (bool) ($in['failed'] ?? true);
    $cur = $store->setNodeFailed($node, $failed);
    exit(json_out([
        'node' => $node,
        'failed' => $failed,
        'failedNodes' => $cur,
        'note' => $failed ? $node . ' 已標記為失效，讀其上 block 會回 MISSING' : $node . ' 已恢復',
    ]));
}

// ---- GET /api/presign：產生預簽 URL ----
if ($method === 'GET' && $path === '/api/presign') {
    $bucket = (string) ($_GET['bucket'] ?? '');
    $key = (string) ($_GET['key'] ?? '');
    $ttl = (int) ($_GET['ttl'] ?? 60);
    $p = $store->presign('GET', $bucket, $key, $ttl);
    exit(json_out([
        'presigned' => $p,
        'note' => '把 url 直接丟給 GET /api/object 即可下載；過了 expires 或竄改 sig 會 403。',
    ]));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out($store->state()));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($store);
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

function render_home(ObjectStore $store): void
{
    $st = $store->state();
    $nodeRows = '';
    foreach ($st['nodes'] as $n => $info) {
        $badge = $info['up'] ? '<span style="color:#7ee787">● up</span>' : '<span style="color:#ff7b72">✗ failed</span>';
        $nodeRows .= '<tr><td><code>' . htmlspecialchars((string) $n) . '</code></td>'
            . '<td>' . $badge . '</td><td>' . (int) $info['blocks'] . ' blocks</td>'
            . '<td><button onclick="toggleNode(\'' . htmlspecialchars((string) $n) . '\',' . ($info['up'] ? 'true' : 'false') . ')">'
            . ($info['up'] ? '製造失效' : '恢復') . '</button></td></tr>';
    }

    $objRows = '';
    foreach ($store->listObjects() as $o) {
        $objRows .= '<tr><td><code>' . htmlspecialchars((string) $o['bucket']) . '/' . htmlspecialchars((string) $o['key']) . '</code></td>'
            . '<td>' . (int) $o['size'] . ' B</td>'
            . '<td>' . htmlspecialchars((string) $o['strategy']) . '</td>'
            . '<td><button onclick="fetchObj(\'' . htmlspecialchars((string) $o['bucket']) . '\',\'' . htmlspecialchars((string) $o['key']) . '\')">下載/還原</button>'
            . ' <button onclick="presign(\'' . htmlspecialchars((string) $o['bucket']) . '\',\'' . htmlspecialchars((string) $o['key']) . '\')">預簽 URL</button></td></tr>';
    }
    if ($objRows === '') {
        $objRows = '<tr><td colspan="4" style="color:#888">尚無物件，請用下方「一鍵上傳」</td></tr>';
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>物件儲存 S3 · Demo</title>
<style>
body{max-width:920px;margin:36px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#16181c;color:#e6e6e6;line-height:1.6}
h1{font-size:1.5rem}h2{font-size:1.1rem;margin-top:1.8em;border-bottom:1px solid #333;padding-bottom:.3em}
code{background:#222;padding:2px 6px;border-radius:4px;color:#7ee787}
.box{border-left:4px solid #58a6ff;background:#1b1d22;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
table{width:100%;border-collapse:collapse;margin:.6em 0}td,th{border:1px solid #333;padding:7px 9px;text-align:left;font-size:.92rem}
th{background:#20242b}
input,select,button{font-size:.92rem;padding:6px 8px;border-radius:6px;border:1px solid #444;background:#0f1115;color:#e6e6e6;margin:2px}
button{background:#1f6feb;border-color:#1f6feb;cursor:pointer}button:hover{background:#388bfd}
.row{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:.4em 0}
pre{background:#0f1115;border:1px solid #333;border-radius:8px;padding:12px;overflow:auto;font-size:.85rem;max-height:420px}
label{font-size:.85rem;color:#aab}
</style>
</head><body>
<h1>🪣 物件儲存 S3 · Demo</h1>
<p>真實邏輯：<strong>multipart 上傳</strong>（init→part→complete，算 ETag）、<strong>一致性雜湊</strong>放置、<strong>副本 vs 抹除碼</strong>、<strong>節點失效還原</strong>、<strong>預簽 URL</strong>（hash_hmac）。data/nodes/ 下子目錄即「儲存節點」。</p>

<h2>① 一鍵 multipart 上傳</h2>
<div class="row">
  bucket <input id="u_bucket" value="my-bucket" size="10">
  key <input id="u_key" value="hello.txt" size="12">
  策略 <select id="u_strat"><option value="EC">抹除碼 EC (k2+m1, 1.5x)</option><option value="REPLICA">副本 REPLICA (R=3, 3x)</option></select>
  <button onclick="upload()">init→2 parts→complete</button>
</div>
<div class="box"><span class="label">提示</span>會上傳 2 個 part（兩段文字），complete 時組裝、算 multipart ETag，依策略把分片/副本放到一致性雜湊選出的節點。</div>

<h2>② 儲存節點（點「製造失效」模擬節點掛掉）</h2>
<table><tr><th>node</th><th>狀態</th><th>block</th><th>操作</th></tr>$nodeRows</table>

<h2>③ 物件（下載會自動偵測失效節點並用副本/parity 還原）</h2>
<table><tr><th>bucket/key</th><th>大小</th><th>策略</th><th>操作</th></tr>$objRows</table>

<h2>輸出</h2>
<pre id="out">（操作結果顯示於此；上傳/失效後請重新整理頁面看節點 block 與物件列表）</pre>

<p style="color:#888;font-size:.82rem;margin-top:2em">※ 用檔案子目錄模擬儲存節點，無真正網路/分散式協調/背景修復。但 multipart 組裝與 ETag、一致性雜湊放置、抹除碼編碼/還原、副本容錯、預簽 URL 簽章驗章皆為真實可執行。</p>

<script>
const out = document.getElementById('out');
function show(o){ out.textContent = JSON.stringify(o, null, 2); }
async function post(url, body){ const r = await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); return r.json(); }
async function get(url){ const r = await fetch(url); return r.json(); }
function v(id){ return document.getElementById(id).value; }

async function upload(){
  const bucket=v('u_bucket'), key=v('u_key'), strat=v('u_strat');
  const init = await post('/api/upload?op=init',{bucket,key});
  const id = init.uploadId;
  await post('/api/upload?op=part',{uploadId:id,partNumber:1,content:'Hello Object Storage — '});
  await post('/api/upload?op=part',{uploadId:id,partNumber:2,content:'this is part two. 物件儲存 S3 demo.'});
  const done = await post('/api/upload?op=complete',{uploadId:id,strategy:strat,replicas:3});
  show(done);
  setTimeout(()=>location.reload(), 800);
}
async function toggleNode(node, up){
  show(await post('/api/node-fail',{node, failed: up}));
  setTimeout(()=>location.reload(), 600);
}
async function fetchObj(bucket,key){
  show(await get('/api/object?bucket='+encodeURIComponent(bucket)+'&key='+encodeURIComponent(key)));
}
async function presign(bucket,key){
  const p = await get('/api/presign?bucket='+encodeURIComponent(bucket)+'&key='+encodeURIComponent(key)+'&ttl=60');
  const verify = await get(p.presigned.url);
  show({presigned:p.presigned, verifyResult:verify});
}
</script>
</body></html>
HTML;
}
