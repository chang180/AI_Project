<?php
declare(strict_types=1);

/**
 * Dropbox / Google Drive 檔案同步 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8020 -t public
 *
 * 路由：
 *   GET  /                       測試頁（貼入內容當「檔案」上傳、看分塊去重 / delta / 衝突）
 *   POST /api/upload             上傳新版本 { fileId, content, device, baseVersion }
 *   GET  /api/file/{id}/meta     某檔最新版本的 metadata（有序 chunk 清單）
 *   GET  /api/blob/stats         全域去重統計
 */

require __DIR__ . '/../src/Chunker.php';
require __DIR__ . '/../src/BlobStore.php';
require __DIR__ . '/../src/FileIndex.php';
require __DIR__ . '/../src/SyncService.php';

$dataDir = __DIR__ . '/../data';
$chunker = new Chunker(16);                 // Demo：每 16 byte 一塊，方便觀察
$blobs   = new BlobStore($dataDir);
$index   = new FileIndex($dataDir);
$sync    = new SyncService($chunker, $blobs, $index);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/upload：上傳新版本（分塊去重 + delta + 衝突偵測）----
if ($method === 'POST' && $path === '/api/upload') {
    $in = read_input();
    $fileId  = trim((string)($in['fileId'] ?? 'report.txt'));
    $content = (string)($in['content'] ?? '');
    $device  = trim((string)($in['device'] ?? '裝置A'));
    $baseVer = (int)($in['baseVersion'] ?? $index->latestVersion($fileId));
    if ($content === '') {
        http_response_code(400);
        exit(json_out(['error' => 'content 不可為空']));
    }
    $result = $sync->upload($fileId, $content, $device, $baseVer);
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        // 表單流程：導回首頁並以 query 夾帶結果摘要
        header('Location: /?flash=' . rawurlencode(json_encode($result, JSON_UNESCAPED_UNICODE)));
        exit;
    }
    exit(json_out($result));
}

// ---- GET /api/file/{id}/meta：metadata ----
if ($method === 'GET' && preg_match('#^/api/file/(.+)/meta$#', $path, $m)) {
    $fileId = rawurldecode($m[1]);
    $chunks = $index->chunksOf($fileId);
    if ($chunks === null) {
        http_response_code(404);
        exit(json_out(['error' => 'file not found']));
    }
    exit(json_out([
        'fileId' => $fileId,
        'version' => $index->latestVersion($fileId),
        'chunks' => $chunks,
        'vector_clock' => $index->vectorClock($fileId),
    ]));
}

// ---- GET /api/blob/stats：去重統計 ----
if ($method === 'GET' && $path === '/api/blob/stats') {
    exit(json_out($blobs->stats()));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($index, $blobs);
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
        parse_str($raw, $form);
        return $form;
    }
    return [];
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function render_home(FileIndex $index, BlobStore $blobs): void
{
    $flash = '';
    if (isset($_GET['flash'])) {
        $r = json_decode((string) $_GET['flash'], true);
        if (is_array($r)) {
            $flash = render_flash($r);
        }
    }

    // 列出現有檔案 / 版本
    $rows = '';
    foreach ($index->all() as $fileId => $info) {
        $latest = (int) $info['latest_version'];
        $chunks = $info['versions'][(string) $latest]['chunks'] ?? [];
        $rows .= '<tr><td><code>' . h($fileId) . '</code></td>'
            . '<td>v' . $latest . '</td>'
            . '<td>' . count($chunks) . ' 塊</td>'
            . '<td><a href="/api/file/' . rawurlencode($fileId) . '/meta" target="_blank">metadata↗</a></td></tr>';
    }

    $s = $blobs->stats();
    $statsLine = sprintf(
        '唯一 chunk：<b>%d</b> 塊　·　實際儲存：<b>%d</b> bytes　·　去重命中：<b>%d</b> 次　·　去重省下：<b>%d</b> bytes',
        $s['unique_chunks'] ?? 0,
        $s['stored_bytes'] ?? 0,
        $s['dedup_hits'] ?? 0,
        $s['saved_bytes'] ?? 0
    );

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dropbox 檔案同步 · Demo</title>
<style>
  :root{--bg:#0f1419;--card:#1a2027;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--green:#7ee787;--warn:#f0883e}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.7}
  .wrap{max-width:880px;margin:0 auto;padding:28px 20px 80px}
  h1{font-size:1.6rem}h2{font-size:1.2rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin-top:1.6em}
  a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
  code{background:#161b22;color:var(--green);padding:2px 6px;border-radius:6px;font-size:.88em}
  label{display:block;color:var(--dim);font-size:.85rem;margin:.6em 0 .2em}
  input,textarea,select{width:100%;background:#0b0f14;color:var(--text);border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-family:inherit}
  textarea{min-height:90px;font-family:ui-monospace,Consolas,monospace}
  button{margin-top:12px;background:var(--accent);color:#04111f;border:0;border-radius:8px;padding:10px 18px;font-weight:700;cursor:pointer}
  .card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin:1em 0}
  .box{border-left:4px solid var(--accent);background:#11161c;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
  .box.tip{border-color:var(--green)}.box.warn{border-color:var(--warn)}
  .box .label{font-weight:700;display:block;margin-bottom:.2em}
  table{width:100%;border-collapse:collapse;margin:.6em 0;font-size:.92rem}
  th,td{border:1px solid var(--border);padding:8px 10px;text-align:left}th{background:#11161c;color:var(--accent)}
  .hint{color:var(--dim);font-size:.82rem}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  pre{background:#0b0f14;border:1px solid var(--border);border-radius:8px;padding:10px;overflow:auto;font-size:.8rem}
</style></head><body>
<div class="wrap">
<h1>🗂️ Dropbox / Google Drive 檔案同步 · Demo</h1>
<p class="hint">聚焦三大核心：<b>分塊去重</b>（相同內容只存一份）、<b>增量同步</b>（只上傳變更的 chunk）、<b>衝突偵測</b>（並發改同檔產生衝突副本）。把下方文字當作一個「檔案」上傳。</p>

$flash

<div class="card">
  <h2 style="margin-top:0;border:0">上傳一個「檔案」版本</h2>
  <form method="post" action="/api/upload">
    <div class="grid">
      <div><label>檔案名稱 fileId</label><input name="fileId" value="report.txt"></div>
      <div><label>裝置 device</label>
        <select name="device"><option>裝置A</option><option>裝置B</option></select></div>
    </div>
    <label>基底版本 baseVersion（你修改時所基於的版本；首次填 0）</label>
    <input name="baseVersion" value="0">
    <label>檔案內容（貼入文字 → 會被切成多個 chunk）</label>
    <textarea name="content">The quick brown fox jumps over the lazy dog.</textarea>
    <button type="submit">上傳 / 同步</button>
  </form>
  <p class="hint">操作建議：①先以 baseVersion=0 上傳一段內容　②小改內容、baseVersion 填剛回傳的版本再上傳，看「只有變更 chunk 被新增」與 delta 比率　③切到裝置B、baseVersion 仍填舊版本上傳改動 → 偵測衝突、產生衝突副本。</p>
</div>

<div class="card">
  <h2 style="margin-top:0;border:0">全域去重統計（Blob 物件儲存）</h2>
  <p>$statsLine</p>
  <p class="hint"><a href="/api/blob/stats" target="_blank">/api/blob/stats ↗</a></p>
</div>

<h2>現有檔案 / 版本（Metadata DB）</h2>
<table><tr><th>檔案</th><th>最新版本</th><th>chunk 數</th><th>操作</th></tr>$rows</table>

<div class="box warn"><span class="label">誠實聲明</span>
為零依賴展示，本 Demo 用<b>固定大小分塊</b>（真實 Dropbox 用 CDC 內容定義分塊抗邊界位移）、以 JSON 檔模擬 metadata DB、本機檔案模擬 blob 物件儲存。
本機 PHP 未載入 mbstring，故<b>以位元組切塊</b>——這與真實系統處理二進位內容的方式一致。
分塊、sha256 內容定址、去重統計、delta 差集、版本向量衝突偵測等<b>系統邏輯為真實可執行</b>。
</div>
</div>
</body></html>
HTML;
}

function render_flash(array $r): string
{
    $hashes = '<pre>' . h(implode("\n", $r['chunk_hashes'] ?? [])) . '</pre>';
    if (($r['status'] ?? '') === 'conflict') {
        return '<div class="box warn"><span class="label">⚠️ 偵測到衝突（並發修改）</span>'
            . '伺服器目前為 <b>v' . (int)$r['server_version'] . '</b>，但你的基底版本是 <b>v' . (int)$r['base_version'] . '</b>（落後）。'
            . '為避免靜默覆蓋，已將你的內容另存為衝突副本：<br><code>' . h((string)$r['conflict_copy']) . '</code>'
            . '（v' . (int)$r['conflict_version'] . '，去重後新存 ' . (int)$r['newly_stored'] . ' 塊）。<br>'
            . '本次切成 ' . (int)$r['total_chunks'] . ' 塊，沿用既有（去重）' . (int)$r['reused_chunks'] . ' 塊。'
            . $hashes . '</div>';
    }
    return '<div class="box tip"><span class="label">✅ 上傳成功 v' . (int)($r['version'] ?? 0) . '</span>'
        . '切成 <b>' . (int)($r['total_chunks'] ?? 0) . '</b> 塊　·　'
        . '本次實際上傳（delta，新出現的 chunk）<b>' . (int)($r['missing_chunks'] ?? 0) . '</b> 塊　·　'
        . '去重沿用 <b>' . (int)($r['reused_chunks'] ?? 0) . '</b> 塊<br>'
        . '總位元組 ' . (int)($r['total_bytes'] ?? 0) . '，delta 僅上傳 ' . (int)($r['delta_bytes'] ?? 0)
        . ' bytes，<b>省下 ' . h((string)($r['saved_ratio'] ?? 0)) . '%</b> 頻寬。'
        . $hashes . '</div>';
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
