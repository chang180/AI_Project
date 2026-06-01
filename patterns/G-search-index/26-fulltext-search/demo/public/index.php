<?php
declare(strict_types=1);

/**
 * 全文搜尋引擎 Twitter Search Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8026 -t public
 *
 * 路由：
 *   GET  /                        測試頁（加文件、搜尋框、選 AND/OR、列出排序結果）
 *   POST /api/doc                 新增文件 { title, body }
 *   GET  /api/search?q=&op=and|or 搜尋，回 BM25 排序結果（docId + 分數 + 命中詞）
 */

require __DIR__ . '/../src/InvertedIndex.php';

$index = new InvertedIndex(__DIR__ . '/../data');
seed_if_empty($index);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/doc：新增文件 ----
if ($method === 'POST' && $path === '/api/doc') {
    $title = '';
    $body = '';
    if (isset($_POST['title']) || isset($_POST['body'])) {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
    } elseif ($raw = file_get_contents('php://input')) {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $title = trim((string) ($j['title'] ?? ''));
            $body = trim((string) ($j['body'] ?? ''));
        }
    }
    if ($title === '' && $body === '') {
        http_response_code(400);
        exit(json_out(['error' => 'title 或 body 至少一個不可為空']));
    }
    $id = $index->addDocument($title, $body);
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        header('Location: /?added=' . $id);
        exit;
    }
    exit(json_out(['id' => $id, 'title' => $title, 'body' => $body]));
}

// ---- GET /api/search：搜尋 ----
if ($method === 'GET' && $path === '/api/search') {
    $q = (string) ($_GET['q'] ?? '');
    $op = strtolower((string) ($_GET['op'] ?? 'or'));
    $op = in_array($op, ['and', 'or'], true) ? $op : 'or';
    $hits = $index->search($q, $op);
    exit(json_out([
        'query'   => $q,
        'op'      => $op,
        'total'   => count($hits),
        'avgdl'   => round($index->avgdl(), 2),
        'docs'    => $index->docCount(),
        'results' => array_map(static fn(array $r): array => [
            'id'      => $r['id'],
            'title'   => $r['title'],
            'score'   => $r['score'],
            'matched' => $r['matched'],
        ], $hits),
    ]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($index);
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

/** 第一次啟動時塞幾筆範例文件（模擬 Twitter 貼文） */
function seed_if_empty(InvertedIndex $index): void
{
    if ($index->docCount() > 0) {
        return;
    }
    $samples = [
        ['Search engines', 'A full text search engine ranks documents by relevance using an inverted index.'],
        ['BM25 ranking', 'BM25 is a ranking function that scores documents by term frequency and inverse document frequency.'],
        ['Inverted index', 'An inverted index maps each term to the list of documents that contain it, called a postings list.'],
        ['Elasticsearch shards', 'Elasticsearch splits an index into shards and replicas for scale and high availability.'],
        ['Twitter search', 'Twitter search must index millions of new tweets per minute with near real time refresh.'],
        ['Lucene library', 'Lucene is the search library that powers Elasticsearch and Solr with fast inverted index lookups.'],
    ];
    foreach ($samples as [$title, $body]) {
        $index->addDocument($title, $body);
    }
}

function render_home(InvertedIndex $index): void
{
    $added = isset($_GET['added']) ? (int) $_GET['added'] : 0;
    $q = trim((string) ($_GET['q'] ?? ''));
    $op = strtolower((string) ($_GET['op'] ?? 'or'));
    $op = in_array($op, ['and', 'or'], true) ? $op : 'or';

    // 搜尋結果
    $resultHtml = '';
    if ($q !== '') {
        $hits = $index->search($q, $op);
        if ($hits === []) {
            $resultHtml = "<p>查無結果（query=<code>" . h($q) . "</code>, op=<code>$op</code>）。</p>";
        } else {
            $rows = '';
            $rank = 1;
            foreach ($hits as $r) {
                $matched = implode(' ', array_map(static fn(string $t): string => '<code>' . h($t) . '</code>', $r['matched']));
                $rows .= "<tr><td>{$rank}</td><td>#{$r['id']}</td><td>" . h($r['title']) . "</td>"
                    . "<td><b>{$r['score']}</b></td><td>{$matched}</td></tr>";
                $rank++;
            }
            $resultHtml = "<h2>搜尋結果（" . count($hits) . " 筆，op=<code>$op</code>，依 BM25 分數排序）</h2>"
                . "<table><tr><th>名次</th><th>docId</th><th>標題</th><th>BM25</th><th>命中詞</th></tr>$rows</table>";
        }
    }

    // 現有文件
    $docRows = '';
    foreach ($index->allDocs() as $d) {
        $snippet = mb_strimwidth_ascii($d['body'], 70);
        $docRows .= "<tr><td>#{$d['id']}</td><td>" . h($d['title']) . "</td><td style='color:#aaa'>" . h($snippet) . "</td></tr>";
    }

    $banner = $added > 0
        ? "<div class='box'><span class='label'>已新增文件 #{$added}，索引已更新。</span></div>"
        : '';

    $stats = sprintf(
        '文件數 <b>%d</b> · 詞典大小 <b>%d</b> · 平均文件長度 avgdl <b>%.2f</b>',
        $index->docCount(),
        $index->termCount(),
        $index->avgdl()
    );

    $qEsc = h($q);
    $andSel = $op === 'and' ? 'selected' : '';
    $orSel = $op === 'or' ? 'selected' : '';

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>全文搜尋引擎 Twitter Search · Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>body{max-width:820px;margin:40px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block}code{background:#222;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse;font-size:.92rem}td,th{border:1px solid #444;padding:6px 8px;text-align:left}
form{margin:1em 0}input,select,button{font-size:1rem}</style>
</head><body>
<h1>🔍 全文搜尋引擎 Twitter Search · Demo</h1>
<p>倒排索引 + <b>BM25</b> 排序 + 布林 <code>AND</code>/<code>OR</code> 查詢。$stats</p>
$banner

<h2>搜尋</h2>
<form method="get" action="/">
  <input name="q" value="$qEsc" placeholder="例如：inverted index" style="width:55%" autofocus>
  <select name="op"><option value="or" $orSel>OR（任一詞）</option><option value="and" $andSel>AND（全部詞）</option></select>
  <button type="submit">搜尋</button>
</form>
$resultHtml

<h2>新增文件</h2>
<form method="post" action="/api/doc">
  <input name="title" placeholder="標題" style="width:40%">
  <input name="body" placeholder="內文" style="width:40%">
  <button type="submit">加入索引</button>
</form>

<h2>現有文件（共 {$index->docCount()} 筆）</h2>
<table><tr><th>docId</th><th>標題</th><th>內文（截斷）</th></tr>$docRows</table>

<p style="color:#888;font-size:.85rem">※ BM25 評分、倒排索引、AND/OR 布林查詢為真實邏輯；未做分片 / 位置索引 / 片語查詢（誠實聲明見 notes 第 9 段）。</p>
</body></html>
HTML;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** ASCII 安全截斷（不用 mb_*）；以 byte 為準，英文 demo 足夠 */
function mb_strimwidth_ascii(string $s, int $max): string
{
    if (strlen($s) <= $max) {
        return $s;
    }
    return substr($s, 0, $max) . '…';
}
