<?php
declare(strict_types=1);

/**
 * 網頁搜尋引擎（全鏈）Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8038 -t public
 *
 * 路由：
 *   GET  /                測試頁（加文件、建分片索引、算 PageRank、查詢看融合排序）
 *   POST /api/doc         加文件 { title, body, links:[docId...] }
 *   POST /api/pagerank    在連結圖上算 PageRank { damping? }
 *   GET  /api/search      查詢 ?q=&op=&alpha=&beta=（scatter-gather + BM25+PageRank 融合）
 *   GET  /api/state       目前狀態（分片分布、PageRank、文件、快取統計）
 */

require __DIR__ . '/../src/ShardedIndex.php';

$dataDir = __DIR__ . '/../data';
$index = new ShardedIndex($dataDir, 4);

// 首次啟動：塞數篇互相連結的範例文件 + 先算一次 PageRank
seed_if_empty($index);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';

// ---- POST /api/doc：加文件 ----
if ($method === 'POST' && $path === '/api/doc') {
    $in = read_input();
    $title = trim((string) ($in['title'] ?? ''));
    $body  = trim((string) ($in['body'] ?? ''));
    $links = $in['links'] ?? [];
    if (!is_array($links)) {
        $links = array_filter(array_map('trim', explode(',', (string) $links)), 'strlen');
    }
    if ($title === '' && $body === '') {
        http_response_code(400);
        exit(json_out(['error' => 'title 或 body 至少一個非空']));
    }
    $id = $index->addDocument($title, $body, array_map('intval', $links));
    if (wants_html()) { header('Location: /?added=' . $id); exit; }
    exit(json_out(['id' => $id, 'shard' => $index->shardOf($id)]));
}

// ---- POST /api/pagerank：算 PageRank ----
if ($method === 'POST' && $path === '/api/pagerank') {
    $in = read_input();
    $damping = (float) ($in['damping'] ?? 0.85);
    if ($damping <= 0 || $damping >= 1) { $damping = 0.85; }
    $pr = $index->computePageRank($damping);
    if (wants_html()) { header('Location: /?pr=1'); exit; }
    exit(json_out($pr));
}

// ---- GET /api/search：scatter-gather 查詢 + 融合排序 ----
if ($method === 'GET' && $path === '/api/search') {
    $q = (string) ($_GET['q'] ?? '');
    $op = (string) ($_GET['op'] ?? 'or');
    $alpha = isset($_GET['alpha']) ? (float) $_GET['alpha'] : 0.7;
    $beta  = isset($_GET['beta'])  ? (float) $_GET['beta']  : 0.3;
    exit(json_out($index->search($q, $op, $alpha, $beta)));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out([
        'shardCount'   => $index->shardCount(),
        'docCount'     => $index->docCount(),
        'shardStats'   => $index->shardStats(),
        'pagerank'     => $index->pagerankScores(),
        'pagerankMeta' => $index->pagerankMeta(),
        'docs'         => $index->allDocs(),
        'cache'        => $index->cacheStats(),
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

function read_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return [];
}

function wants_html(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** 首次啟動塞範例：數篇互連文件，模擬一個小型「網頁」連結圖 */
function seed_if_empty(ShardedIndex $index): void
{
    if ($index->docCount() > 0) {
        return;
    }
    // 主題：搜尋引擎技術。刻意讓 "search engine hub" 被多頁連入 → PageRank 高。
    $hub = $index->addDocument(
        'Search Engine Hub',
        'a comprehensive guide to search engine technology indexing ranking and retrieval',
        []
    );
    $bm25 = $index->addDocument(
        'BM25 Ranking',
        'bm25 is a relevance ranking function used by search engine for full text scoring',
        [$hub]
    );
    $pagerank = $index->addDocument(
        'PageRank Algorithm',
        'pagerank measures page authority on the web link graph used by search engine ranking',
        [$hub, $bm25]
    );
    $crawler = $index->addDocument(
        'Web Crawler',
        'a crawler fetches web pages to build the index for a search engine',
        [$hub, $pagerank]
    );
    $shard = $index->addDocument(
        'Sharded Index',
        'a sharded inverted index splits postings across shards for scatter gather query',
        [$hub, $bm25, $crawler]
    );
    // 一篇低權威的孤立頁（沒人連它）
    $index->addDocument(
        'Search Tips',
        'simple search tips and tricks for users no deep engine internals here',
        []
    );
    $index->computePageRank(0.85);
}

function render_home(ShardedIndex $index): void
{
    $added = $_GET['added'] ?? null;
    $prFlag = isset($_GET['pr']);

    // 文件列表（含連結）
    $docRows = '';
    foreach ($index->allDocs() as $id => $d) {
        $links = $d['links'] ? implode(', ', $d['links']) : '—';
        $pr = $index->pagerankScores()[$id] ?? null;
        $prTxt = $pr !== null ? round($pr, 5) : '（未算）';
        $docRows .= "<tr><td><code>$id</code></td>"
            . '<td>' . htmlspecialchars($d['title']) . '</td>'
            . "<td>$links</td><td>" . $index->shardOf($id) . "</td>"
            . "<td>$prTxt</td></tr>";
    }

    // 分片分布
    $shardRows = '';
    foreach ($index->shardStats() as $s => $st) {
        $shardRows .= "<tr><td>shard $s</td><td>{$st['docs']}</td><td>{$st['terms']}</td></tr>";
    }

    $meta = $index->pagerankMeta();
    $metaTxt = $meta
        ? "迭代 {$meta['iterations']} 次・"
          . ($meta['converged'] ? '已收斂' : '未收斂')
          . "・damping {$meta['damping']}・dangling {$meta['danglingCount']} 頁"
        : '尚未計算';

    // 範例查詢（在頁面內直接展示融合排序差異）
    $demoQuery = 'search engine ranking';
    $rRelevance = $index->search($demoQuery, 'or', 1.0, 0.0); // 純 BM25
    $rFusion    = $index->search($demoQuery, 'or', 0.5, 0.5); // 融合
    $tableRel = render_result_table($rRelevance);
    $tableFus = render_result_table($rFusion);

    $banner = '';
    if ($added !== null) {
        $banner = "<div class='box tip'><span class='label'>已加入文件 #$added</span>"
            . '記得重新計算 PageRank 讓連結圖生效。</div>';
    } elseif ($prFlag) {
        $banner = "<div class='box tip'><span class='label'>PageRank 已重算</span>$metaTxt</div>";
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>網頁搜尋引擎（全鏈）· Demo</title>
<link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura-dark.css" media="screen">
<style>
body{max-width:900px;margin:36px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif}
.box{border-left:4px solid #7ee787;background:#1b1b1b;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block}
.box.warn{border-color:#ffa657}
code{background:#222;padding:2px 6px;border-radius:4px}
table{width:100%;border-collapse:collapse;margin:.5em 0;font-size:.92rem}
td,th{border:1px solid #444;padding:6px 8px;text-align:left}
.cols{display:flex;gap:16px;flex-wrap:wrap}
.cols>div{flex:1;min-width:300px}
h2{border-bottom:1px solid #333;padding-bottom:4px;margin-top:1.6em}
form{margin:.5em 0}
input,textarea{width:100%;box-sizing:border-box;margin:3px 0}
.small{color:#888;font-size:.85rem}
</style></head><body>
<h1>🔎 網頁搜尋引擎（全鏈）· Demo</h1>
<p>爬→索引→排序→服務全鏈：<strong>分片倒排索引</strong> + <strong>scatter-gather 查詢</strong>
 + 在連結圖上算 <strong>PageRank</strong> + <strong>BM25×PageRank 融合排序</strong>。</p>
$banner

<h2>① 文件與連結圖</h2>
<table><tr><th>docId</th><th>標題</th><th>連到</th><th>shard</th><th>PageRank</th></tr>$docRows</table>
<form method="post" action="/api/doc">
  <input name="title" placeholder="標題（英文 token 才會進索引）" required>
  <textarea name="body" rows="2" placeholder="內文，例如：search engine ranking with bm25"></textarea>
  <input name="links" placeholder="連到的 docId（逗號分隔，例如 100,101）">
  <button type="submit">加文件</button>
</form>
<form method="post" action="/api/pagerank" style="display:inline">
  <button type="submit">在連結圖上重算 PageRank（damping 0.85）</button>
</form>
<p class="small">PageRank 狀態：$metaTxt</p>

<h2>② 分片分布（scatter-gather 的目標）</h2>
<table><tr><th>shard</th><th>文件數</th><th>term 數</th></tr>$shardRows</table>
<p class="small">文件依 <code>crc32(docId) % 4</code> 分到 4 個 shard，各持有自己的局部倒排索引。</p>

<h2>③ 融合排序：加入 PageRank 後排序怎麼變？</h2>
<p>同一查詢 <code>$demoQuery</code>，左邊純相關性，右邊相關性+權威各半，看名次變化：</p>
<div class="cols">
  <div><strong>α=1.0, β=0.0（純 BM25 相關性）</strong>$tableRel</div>
  <div><strong>α=0.5, β=0.5（BM25 + PageRank 融合）</strong>$tableFus</div>
</div>
<p class="small">被多頁連入的權威頁（如 Search Engine Hub）在右側通常名次上升。</p>

<h2>④ 自己查詢（試試調 α / β、AND / OR、看快取命中）</h2>
<form method="get" action="/api/search" target="_blank">
  <input name="q" value="search engine ranking" placeholder="查詢詞">
  <div class="cols">
    <div>op：<input name="op" value="or" placeholder="or / and"></div>
    <div>alpha（相關性權重）：<input name="alpha" value="0.7"></div>
    <div>beta（權威權重）：<input name="beta" value="0.3"></div>
  </div>
  <button type="submit">查詢（回傳 JSON：perShardCandidates 看 scatter，results 看融合排序，cached 看快取）</button>
</form>
<p class="small">相同 (查詢, α, β) 第二次送出 → <code>cached:true</code>（查詢快取命中）。
 也可看 <a href="/api/state" target="_blank">/api/state</a>。</p>

<div class="box warn"><span class="label">誠實聲明</span>
單機模擬多個 shard（用迴圈代替平行送出）；但<strong>分片、scatter-gather、BM25、
PageRank 冪次迭代、融合排序、查詢快取都是真實可執行的邏輯</strong>，只是規模小。
真實系統 shard 分散在不同機器、平行查詢、全域統計需分散維護。
</div>
</body></html>
HTML;
}

function render_result_table(array $res): string
{
    $rows = '';
    $rank = 1;
    foreach ($res['results'] as $r) {
        $rows .= "<tr><td>$rank</td><td>" . htmlspecialchars((string) $r['title'])
            . "</td><td>{$r['score']}</td><td class='small'>bm25 {$r['normBm25']} / pr {$r['normPr']}</td></tr>";
        $rank++;
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="4">（無結果）</td></tr>';
    }
    return "<table><tr><th>#</th><th>標題</th><th>融合分</th><th>細項</th></tr>$rows</table>";
}
