<?php
declare(strict_types=1);

/**
 * YouTube 影片儲存/串流 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8010 -t public
 *
 * 聚焦：上傳（分塊→合併）→ 轉碼管線狀態機 → 自適應串流 manifest，而非 CRUD。
 *
 * 路由：
 *   GET  /                                          測試頁
 *   POST /api/v1/uploads                            建立上傳 { filename,size,title } → upload_id + 預簽章 URL
 *   PUT  /api/v1/uploads/{uid}/parts/{i}            上傳第 i 塊（body=bytes）
 *   POST /api/v1/uploads/{uid}/complete             合併分塊 → 建影片 → 觸發轉碼（202）
 *   POST /api/v1/videos/{vid}/transcode/step        推進轉碼一步（模擬 Worker）
 *   POST /api/v1/videos/{vid}/transcode/run         一路推到全 READY
 *   GET  /api/v1/videos/{vid}                       影片狀態 + 各解析度 job 狀態
 *   GET  /api/v1/videos/{vid}/master.m3u8           master manifest（自適應）
 *   GET  /api/v1/videos/{vid}/{rendition}/index.m3u8 某解析度 media manifest
 *   POST /api/v1/videos/{vid}/view                  觀看數 +1
 *
 *   表單便捷端點（測試頁用，導回首頁）：
 *   POST /demo/upload   { title,(text) } 一鍵：建立→分 3 塊上傳→合併
 *   POST /demo/step     { video_id }     推進一步
 *   POST /demo/run      { video_id }     推到全 READY
 *   POST /demo/view     { video_id }     觀看 +1
 */

require __DIR__ . '/../src/MetadataStore.php';
require __DIR__ . '/../src/TranscodePipeline.php';
require __DIR__ . '/../src/UploadService.php';

$store    = new MetadataStore(__DIR__ . '/../data');
$pipeline = new TranscodePipeline($store);
$uploads  = new UploadService($store, $pipeline);

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($path, '/') ?: '/';

try {
    // ---- POST /api/v1/uploads：建立上傳 ----
    if ($method === 'POST' && $path === '/api/v1/uploads') {
        $in = body_json();
        $filename = trim((string) ($in['filename'] ?? 'video.mp4'));
        $size     = (int) ($in['size'] ?? 0);
        $title    = trim((string) ($in['title'] ?? '未命名'));
        if ($size <= 0) {
            throw new RuntimeException('size 必須 > 0', 400);
        }
        http_response_code(201);
        exit(json_out($uploads->createUpload($filename, $size, $title, base_url())));
    }

    // ---- PUT /api/v1/uploads/{uid}/parts/{i}：上傳分塊 ----
    if ($method === 'PUT' && preg_match('#^/api/v1/uploads/([\w]+)/parts/(\d+)$#', $path, $m)) {
        $bytes = (string) file_get_contents('php://input');
        exit(json_out($uploads->receivePart($m[1], (int) $m[2], $bytes)));
    }

    // ---- POST /api/v1/uploads/{uid}/complete：完成上傳 ----
    if ($method === 'POST' && preg_match('#^/api/v1/uploads/([\w]+)/complete$#', $path, $m)) {
        http_response_code(202);
        exit(json_out($uploads->complete($m[1])));
    }

    // ---- POST /api/v1/videos/{vid}/transcode/step ----
    if ($method === 'POST' && preg_match('#^/api/v1/videos/([\w]+)/transcode/step$#', $path, $m)) {
        exit(json_out($pipeline->step($m[1])));
    }

    // ---- POST /api/v1/videos/{vid}/transcode/run ----
    if ($method === 'POST' && preg_match('#^/api/v1/videos/([\w]+)/transcode/run$#', $path, $m)) {
        $pipeline->runToReady($m[1]);
        exit(json_out($store->getVideo($m[1]) ?? []));
    }

    // ---- GET /api/v1/videos/{vid}/master.m3u8 ----
    if ($method === 'GET' && preg_match('#^/api/v1/videos/([\w]+)/master\.m3u8$#', $path, $m)) {
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Cache-Control: public, max-age=10'); // manifest 短 TTL（可能新增解析度）
        echo $pipeline->buildMasterManifest($m[1]);
        exit;
    }

    // ---- GET /api/v1/videos/{vid}/{rendition}/index.m3u8 ----
    if ($method === 'GET' && preg_match('#^/api/v1/videos/([\w]+)/(\d+p)/index\.m3u8$#', $path, $m)) {
        $obj = $store->getObject("{$m[1]}/{$m[2]}/index.m3u8");
        if ($obj === null) {
            throw new RuntimeException('該解析度尚未就緒', 404);
        }
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Cache-Control: public, max-age=31536000, immutable'); // segment/子清單內容固定，可長快取
        echo $obj;
        exit;
    }

    // ---- POST /api/v1/videos/{vid}/view：觀看 +1 ----
    if ($method === 'POST' && preg_match('#^/api/v1/videos/([\w]+)/view$#', $path, $m)) {
        $v = $store->updateVideo($m[1], function (array $v) {
            $v['views'] = ($v['views'] ?? 0) + 1; // 真實系統走 Kafka 非同步聚合
            return $v;
        });
        if ($v === null) {
            throw new RuntimeException('video_id 不存在', 404);
        }
        exit(json_out(['video_id' => $m[1], 'views' => $v['views']]));
    }

    // ---- GET /api/v1/videos/{vid}：影片狀態 ----
    if ($method === 'GET' && preg_match('#^/api/v1/videos/([\w]+)$#', $path, $m)) {
        $v = $store->getVideo($m[1]);
        if ($v === null) {
            throw new RuntimeException('video_id 不存在', 404);
        }
        exit(json_out($v));
    }

    // ---- 表單便捷端點（測試頁用）----
    if ($method === 'POST' && $path === '/demo/upload') {
        $title = trim((string) ($_POST['title'] ?? '測試影片'));
        $text  = (string) ($_POST['content'] ?? str_repeat('VIDEO-BYTES;', 2000));
        // 一鍵：建立 → 分 3 塊上傳 → 合併
        $created = $uploads->createUpload('demo.mp4', max(1, strlen($text)), $title, base_url());
        $uid = $created['upload_id'];
        $parts = (int) $created['total_parts'];
        $chunks = str_split_n($text, $parts);
        foreach ($chunks as $i => $chunk) {
            $uploads->receivePart($uid, $i, $chunk);
        }
        $done = $uploads->complete($uid);
        header('Location: /?video=' . $done['video_id']);
        exit;
    }
    if ($method === 'POST' && $path === '/demo/step') {
        $pipeline->step((string) ($_POST['video_id'] ?? ''));
        header('Location: /?video=' . urlencode((string) ($_POST['video_id'] ?? '')));
        exit;
    }
    if ($method === 'POST' && $path === '/demo/run') {
        $pipeline->runToReady((string) ($_POST['video_id'] ?? ''));
        header('Location: /?video=' . urlencode((string) ($_POST['video_id'] ?? '')));
        exit;
    }
    if ($method === 'POST' && $path === '/demo/view') {
        $vid = (string) ($_POST['video_id'] ?? '');
        $store->updateVideo($vid, function (array $v) {
            $v['views'] = ($v['views'] ?? 0) + 1;
            return $v;
        });
        header('Location: /?video=' . urlencode($vid));
        exit;
    }

    // ---- GET /：測試頁 ----
    if ($method === 'GET' && $path === '/') {
        render_home($store, $pipeline, base_url());
        exit;
    }

    http_response_code(404);
    echo 'not found';
} catch (RuntimeException $e) {
    $code = $e->getCode();
    http_response_code($code >= 400 && $code < 600 ? $code : 500);
    exit(json_out(['error' => $e->getMessage()]));
}

// ============ 輔助函式 ============
function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function body_json(): array
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

function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8010');
}

/** 把字串平均切成 n 段（給 demo 分塊上傳用） */
function str_split_n(string $s, int $n): array
{
    $len = max(1, (int) ceil(strlen($s) / $n));
    $parts = str_split($s, $len);
    return $parts === [''] ? [''] : $parts;
}

function render_home(MetadataStore $store, TranscodePipeline $pipeline, string $base): void
{
    $focus = $_GET['video'] ?? null;

    $rows = '';
    foreach ($store->allVideos() as $vid => $v) {
        $rend = '';
        foreach (($v['renditions'] ?? []) as $name => $r) {
            $cls = match ($r['status']) {
                'READY' => 'ready',
                'TRANSCODING' => 'work',
                'FAILED' => 'fail',
                default => 'queued',
            };
            $rend .= "<span class='pill $cls'>{$name}:{$r['status']}</span> ";
        }
        if ($rend === '') {
            $rend = "<span class='pill queued'>尚未轉碼</span>";
        }
        $sel = ($vid === $focus) ? " style='outline:2px solid #58a6ff'" : '';
        $rows .= "<tr$sel><td><code>" . htmlspecialchars($vid) . "</code><br><small>" . htmlspecialchars($v['title']) . "</small></td>"
            . "<td><span class='pill " . strtolower($v['status']) . "'>" . $v['status'] . "</span></td>"
            . "<td>$rend</td>"
            . "<td>{$v['views']}</td>"
            . "<td class='ops'>"
            . form_btn('/demo/step', $vid, '推進轉碼一步')
            . form_btn('/demo/run', $vid, '推到全 READY')
            . form_btn('/demo/view', $vid, '觀看 +1')
            . "<a href='$base/api/v1/videos/$vid/master.m3u8' target='_blank'>master.m3u8</a>"
            . " · <a href='$base/api/v1/videos/$vid' target='_blank'>狀態 JSON</a>"
            . "</td></tr>";
    }

    $manifest = '';
    if ($focus !== null && $store->getVideo($focus) !== null) {
        $m = htmlspecialchars($pipeline->buildMasterManifest($focus));
        $manifest = "<h2>master.m3u8（影片 <code>" . htmlspecialchars($focus) . "</code>，自適應）</h2>"
            . "<pre>$m</pre>"
            . "<p class='muted'>※ 只列出已 READY 的解析度（漸進可用）。推進轉碼後重整本頁，會看到更多解析度出現。</p>";
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>YouTube 影片儲存/串流 · Demo</title>
<style>
  :root{--bg:#0f1419;--card:#1e2630;--soft:#1a2027;--border:#2a3540;--text:#e6edf3;--dim:#9aa7b3;--accent:#58a6ff;--ok:#7ee787;--warn:#f0883e;--danger:#ff7b72}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.7}
  .wrap{max-width:980px;margin:0 auto;padding:28px 24px 80px}
  h1{font-size:1.7rem;margin:.2em 0}
  h2{font-size:1.2rem;border-bottom:1px solid var(--border);padding-bottom:.3em;margin:1.6em 0 .6em}
  a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
  code{background:#161b22;color:var(--ok);padding:2px 6px;border-radius:6px;font-size:.88em}
  pre{background:#161b22;border:1px solid var(--border);border-radius:12px;padding:16px 18px;overflow-x:auto;font-size:.85rem;color:var(--text)}
  .card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin:1em 0}
  table{width:100%;border-collapse:collapse;margin:1em 0;font-size:.9rem}
  th,td{border:1px solid var(--border);padding:9px 11px;text-align:left;vertical-align:top}
  th{background:var(--soft);color:var(--accent)}
  input,button{font:inherit}
  input[type=text]{width:100%;background:var(--soft);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 11px}
  button{background:var(--accent);color:#06121f;border:none;border-radius:8px;padding:7px 12px;cursor:pointer;font-weight:600;margin:2px 0}
  button:hover{filter:brightness(1.1)}
  .ops form{display:inline}
  .ops button{background:var(--soft);color:var(--text);border:1px solid var(--border)}
  .pill{display:inline-block;font-size:.72rem;padding:2px 8px;border-radius:999px;border:1px solid var(--border);margin:1px 0}
  .pill.ready{color:var(--ok);border-color:var(--ok)}
  .pill.work,.pill.transcoding{color:var(--warn);border-color:var(--warn)}
  .pill.queued{color:var(--dim)}
  .pill.fail,.pill.failed{color:var(--danger);border-color:var(--danger)}
  .muted{color:var(--dim);font-size:.85rem}
  .steps{color:var(--dim);font-size:.9rem;padding-left:1.2em}
</style>
</head><body><div class="wrap">
<h1>📺 YouTube 影片儲存/串流 · Demo</h1>
<p class="muted">聚焦：<strong>上傳（分塊→合併）→ 轉碼管線狀態機（QUEUED→TRANSCODING→READY）→ 自適應串流 manifest（HLS）</strong>。</p>

<div class="card">
  <h2 style="margin-top:0;border:none">① 上傳一支影片（一鍵：建立 → 分塊 → 合併 → 觸發轉碼）</h2>
  <form method="post" action="/demo/upload">
    <input type="text" name="title" placeholder="影片標題，例如：貓咪日常" value="貓咪日常">
    <p class="muted">送出後：建立上傳工作階段 → 把內容分 3 塊上傳 → 合併成原片落物件儲存 → 影片進入 <code>QUEUED</code>。</p>
    <button type="submit">上傳影片</button>
  </form>
  <ol class="steps">
    <li>② 在下表對該影片按「推進轉碼一步」，觀察 240p→480p→720p→1080p 依序 <code>QUEUED→TRANSCODING→READY</code>。</li>
    <li>③ 或按「推到全 READY」一次跑完；按「觀看 +1」累加觀看數。</li>
    <li>④ 點 <code>master.m3u8</code> 看自適應 manifest（只列已就緒解析度）。</li>
  </ol>
</div>

<h2>影片清單</h2>
<table>
  <tr><th>影片</th><th>總狀態</th><th>各解析度（轉碼進度）</th><th>觀看數</th><th>操作</th></tr>
  $rows
</table>

$manifest

<p class="muted">※ 本 demo <strong>不做真正影片編碼</strong>：分塊合併、轉碼狀態機、manifest 結構為真實系統邏輯；segment 內容為佔位文字。真實系統用 ffmpeg + 物件儲存(S3) + CDN。</p>
</div></body></html>
HTML;
}

function form_btn(string $action, string $vid, string $label): string
{
    $v = htmlspecialchars($vid);
    $l = htmlspecialchars($label);
    return "<form method='post' action='$action'><input type='hidden' name='video_id' value='$v'><button type='submit'>$l</button></form> ";
}
