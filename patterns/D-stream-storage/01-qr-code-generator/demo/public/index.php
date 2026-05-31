<?php
declare(strict_types=1);

/**
 * QR Code 生成器 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8000 -t public
 *
 * 路由：
 *   GET  /                      測試頁（建立 QR、列出現有）
 *   POST /api/qr                建立動態 QR  { target_url }
 *   GET  /qr/{code}.svg         QR 圖片（SVG 示意）
 *   GET  /api/qr/{code}/stats   掃描統計
 *   GET  /{code}                掃描轉址（302）+ 記錄掃描
 */

require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/ShortCode.php';
require __DIR__ . '/../src/QrRenderer.php';

$store = new Store(__DIR__ . '/../data');
$coder = new ShortCode($store);
$qr = new QrRenderer();

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/qr：建立 ----
if ($method === 'POST' && $path === '/api/qr') {
    $target = trim((string)($_POST['target_url'] ?? ''));
    if ($target === '' && ($raw = file_get_contents('php://input'))) {
        $target = trim((string)(json_decode($raw, true)['target_url'] ?? ''));
    }
    if (!filter_var($target, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        exit(json_out(['error' => 'target_url 不是合法網址']));
    }
    $code = $coder->next();
    $store->put($code, [
        'code' => $code,
        'target_url' => $target,
        'scans' => 0,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ]);
    $base = base_url();
    // 表單送出 → 導回首頁；API 呼叫 → 回 JSON
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        header('Location: /?created=' . $code);
        exit;
    }
    exit(json_out([
        'code' => $code,
        'short_url' => "$base/$code",
        'image_url' => "$base/qr/$code.svg",
    ]));
}

// ---- GET /qr/{code}.svg：圖片 ----
if ($method === 'GET' && preg_match('#^/qr/([0-9A-Za-z]+)\.svg$#', $path, $m)) {
    $rec = $store->get($m[1]);
    if (!$rec) { http_response_code(404); exit('not found'); }
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=31536000, immutable'); // 圖片可永久快取（內容是短碼，不含目標）
    echo $qr->render(base_url() . '/' . $m[1]);
    exit;
}

// ---- GET /api/qr/{code}/stats：統計 ----
if ($method === 'GET' && preg_match('#^/api/qr/([0-9A-Za-z]+)/stats$#', $path, $m)) {
    $rec = $store->get($m[1]);
    if (!$rec) { http_response_code(404); exit(json_out(['error' => 'not found'])); }
    exit(json_out(['code' => $m[1], 'scans' => $rec['scans'], 'cache' => $store->cacheStats()]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($store, base_url());
    exit;
}

// ---- GET /{code}：掃描轉址 ----
if ($method === 'GET' && preg_match('#^/([0-9A-Za-z]+)$#', $path, $m)) {
    $rec = $store->get($m[1]);             // 先查快取
    if (!$rec) { http_response_code(404); exit('短碼不存在'); }
    $store->recordScan($m[1]);             // 非同步示意：記錄一次掃描
    header('Location: ' . $rec['target_url'], true, 302);
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

function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
}

function render_home(Store $store, string $base): void
{
    $created = $_GET['created'] ?? null;
    $rows = '';
    foreach ($store->all() as $code => $r) {
        $rows .= "<tr><td><code>$code</code></td>"
            . "<td><a href=\"" . htmlspecialchars($r['target_url']) . "\" target=\"_blank\">" . htmlspecialchars($r['target_url']) . "</a></td>"
            . "<td>{$r['scans']}</td>"
            . "<td><a href=\"$base/$code\" target=\"_blank\">轉址↗</a> · "
            . "<a href=\"$base/qr/$code.svg\" target=\"_blank\">圖片</a> · "
            . "<a href=\"$base/api/qr/$code/stats\" target=\"_blank\">統計</a></td></tr>";
    }
    $banner = $created
        ? "<div class='box tip'><span class='label'>已建立：$created</span>"
          . "<img src='$base/qr/$created.svg' width='150' alt='qr'><br>"
          . "短網址：<a href='$base/$created' target='_blank'>$base/$created</a></div>"
        : '';

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QR Code Generator · Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>body{max-width:760px;margin:40px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block}code{background:#222;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse}td,th{border:1px solid #444;padding:8px;text-align:left}</style>
</head><body>
<h1>🔳 QR Code 生成器 · Demo</h1>
<p>輸入網址 → 產生動態短碼 QR。掃描走 <code>GET /{code}</code> 轉址並計數。</p>
$banner
<form method="post" action="/api/qr">
  <input name="target_url" placeholder="https://example.com/promo" style="width:70%" required>
  <button type="submit">建立 QR</button>
</form>
<h2>現有 QR</h2>
<table><tr><th>短碼</th><th>目標</th><th>掃描數</th><th>操作</th></tr>$rows</table>
<p style="color:#888;font-size:.85rem">※ 圖片為教學示意（非真正可掃描編碼）；短碼/儲存/快取/轉址/計數為真實邏輯。</p>
</body></html>
HTML;
}
