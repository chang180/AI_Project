<?php
declare(strict_types=1);

/**
 * 分散式交易 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8047 -t public
 *
 * 用「跨兩個分片轉帳」展示兩階段提交（2PC）：
 *   - prepare：協調者問所有參與者「能不能提交?」（各自鎖定/預寫）
 *   - 全 YES → commit（真的套用）；任一 NO/逾時 → abort（全部回滾）
 *
 * 路由：
 *   GET  /                測試頁（看分片餘額、跑 2PC 轉帳、注入失敗、看 prepare/commit 兩階段）
 *   POST /api/transfer    跨分片轉帳（走 2PC）  { from_shard, from_acc, to_shard, to_acc, amount }
 *   POST /api/fail        注入某參與者在某階段失敗  { shard, mode: ''|'prepare'|'commit' }
 *   POST /api/reset       重設帳戶與日誌
 *   GET  /api/state       目前所有分片餘額、鎖定、總額、決議日誌（JSON）
 */

require __DIR__ . '/../src/Coordinator.php';

$dataDir = __DIR__ . '/../data';
$coord = bootstrap($dataDir);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/transfer：跨分片轉帳（2PC） ----
if ($method === 'POST' && $path === '/api/transfer') {
    $in = read_input();
    $fromShard = trim((string)($in['from_shard'] ?? 'A'));
    $fromAcc   = trim((string)($in['from_acc'] ?? 'alice'));
    $toShard   = trim((string)($in['to_shard'] ?? 'B'));
    $toAcc     = trim((string)($in['to_acc'] ?? 'bob'));
    $amount    = (int)($in['amount'] ?? 0);

    if ($amount <= 0) {
        http_response_code(400);
        exit(json_out(['error' => 'amount 必須為正整數']));
    }
    if (!isset($coord->participants()[$fromShard], $coord->participants()[$toShard])) {
        http_response_code(400);
        exit(json_out(['error' => '分片不存在（可用 A / B）']));
    }
    $trace = $coord->transfer($fromShard, $fromAcc, $toShard, $toAcc, $amount);
    if (wants_html()) {
        header('Location: /?tx=' . $trace['txId']);
        exit;
    }
    exit(json_out($trace));
}

// ---- POST /api/fail：注入失敗 ----
if ($method === 'POST' && $path === '/api/fail') {
    $in = read_input();
    $shard = trim((string)($in['shard'] ?? ''));
    $mode  = trim((string)($in['mode'] ?? ''));
    if (!isset($coord->participants()[$shard])) {
        http_response_code(400);
        exit(json_out(['error' => '分片不存在']));
    }
    if (!in_array($mode, ['', 'prepare', 'commit'], true)) {
        http_response_code(400);
        exit(json_out(['error' => 'mode 只能是 空字串 / prepare / commit']));
    }
    $coord->participant($shard)->setFailMode($mode);
    if (wants_html()) {
        header('Location: /?fail=' . $shard . ':' . ($mode ?: 'off'));
        exit;
    }
    exit(json_out(['shard' => $shard, 'failMode' => $mode]));
}

// ---- POST /api/reset：重設 ----
if ($method === 'POST' && $path === '/api/reset') {
    reset_state($dataDir);
    if (wants_html()) { header('Location: /?reset=1'); exit; }
    exit(json_out(['ok' => true]));
}

// ---- GET /api/state：狀態 JSON ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out(state_snapshot($coord)));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($coord);
    exit;
}

http_response_code(404);
echo 'not found';

// ============================================================
// 啟動 / 初始化
// ============================================================
function bootstrap(string $dataDir): Coordinator
{
    $coord = new Coordinator($dataDir);
    // 兩個分片（參與者）：A 放 alice，B 放 bob
    $a = new Participant('A', $dataDir);
    $b = new Participant('B', $dataDir);
    $coord->addParticipant($a);
    $coord->addParticipant($b);

    // 首次啟動給初始餘額
    $seed = $dataDir . '/.seeded';
    if (!file_exists($seed)) {
        $a->setBalance('alice', 1000);
        $b->setBalance('bob', 1000);
        file_put_contents($seed, '1');
    }
    return $coord;
}

function reset_state(string $dataDir): void
{
    foreach (['shard_A.json', 'shard_B.json', 'coordinator_log.json', '.seeded'] as $f) {
        @unlink($dataDir . '/' . $f);
    }
}

// ============================================================
// 輔助函式
// ============================================================
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

function wants_html(): bool
{
    return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function state_snapshot(Coordinator $coord): array
{
    $shards = [];
    foreach ($coord->participants() as $id => $p) {
        $accs = [];
        foreach ($p->accounts() as $acc => $bal) {
            $accs[$acc] = ['balance' => $bal, 'locked' => $p->isLocked($acc)];
        }
        $shards[$id] = [
            'accounts' => $accs,
            'failMode' => $p->failMode(),
            'log'      => $p->logLines(),
        ];
    }
    return [
        'shards'    => $shards,
        'total'     => $coord->totalBalance(),
        'decisions' => $coord->decisions(),
    ];
}

function render_home(Coordinator $coord): void
{
    $st = state_snapshot($coord);
    $total = $st['total'];

    // 分片餘額表
    $shardCards = '';
    foreach ($st['shards'] as $id => $s) {
        $rows = '';
        foreach ($s['accounts'] as $acc => $info) {
            $lock = $info['locked'] ? " 🔒鎖定" : '';
            $rows .= "<tr><td><code>" . htmlspecialchars($acc) . "</code></td><td>{$info['balance']}$lock</td></tr>";
        }
        $fail = $s['failMode'] !== '' ? "<span class='fail'>注入失敗：{$s['failMode']}</span>" : "正常";
        $logHtml = '';
        foreach (array_slice($s['log'], -8) as $line) {
            $logHtml .= '<div class="logline">' . htmlspecialchars($line) . '</div>';
        }
        $shardCards .= "<div class='card'><h3>分片 $id（參與者）— $fail</h3>"
            . "<table><tr><th>帳戶</th><th>餘額</th></tr>$rows</table>"
            . "<div class='log'>$logHtml</div></div>";
    }

    // 決議日誌
    $decHtml = '';
    foreach (array_slice($st['decisions'], -8) as $d) {
        $cls = $d['decision'] === 'COMMIT' ? 'ok' : 'no';
        $decHtml .= "<div class='logline'><span class='$cls'>{$d['decision']}</span> {$d['txId']}</div>";
    }

    // 最近一次交易過程（從 query 提示）
    $banner = '';
    if (isset($_GET['tx'])) {
        $banner = "<div class='box'><span class='label'>剛跑了一筆 2PC 交易：" . htmlspecialchars((string)$_GET['tx'])
            . "</span>看下方分片日誌的 prepare→commit/abort，以及總額是否守恆（應永遠 = 2000）。</div>";
    } elseif (isset($_GET['fail'])) {
        $banner = "<div class='box'><span class='label'>已設定注入：" . htmlspecialchars((string)$_GET['fail'])
            . "</span>現在再跑一次轉帳，看協調者如何收到 NO → 決議 ABORT → 全部回滾，總額不變。</div>";
    } elseif (isset($_GET['reset'])) {
        $banner = "<div class='box'><span class='label'>已重設</span>alice=1000、bob=1000，總額 2000。</div>";
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>分散式交易（2PC）· Demo</title>
<style>
body{max-width:880px;margin:32px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#0f1115;color:#e6e6e6}
h1{font-size:1.5rem}h2{margin-top:1.6em;border-bottom:1px solid #333;padding-bottom:.3em}
a{color:#7ee787}code{background:#222;padding:2px 6px;border-radius:4px}
.total{font-size:1.3rem;font-weight:700}.total b{color:#7ee787}
.cards{display:flex;gap:16px;flex-wrap:wrap}
.card{flex:1;min-width:320px;border:1px solid #333;border-radius:10px;padding:12px 16px;background:#161922}
.card h3{margin:.2em 0 .6em;font-size:1rem}
table{width:100%;border-collapse:collapse;margin:.4em 0}td,th{border:1px solid #333;padding:6px 8px;text-align:left;font-size:.9rem}
.log{margin-top:.6em;font-size:.8rem;color:#9aa}.logline{padding:2px 0;border-bottom:1px dotted #2a2a2a;font-family:monospace}
.ok{color:#7ee787;font-weight:700}.no{color:#ff7b72;font-weight:700}.fail{color:#ff7b72;font-weight:700}
.box{border-left:4px solid #7ee787;background:#161922;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
form{display:inline-block;margin:0 12px 12px 0;background:#161922;border:1px solid #333;border-radius:8px;padding:10px 14px;vertical-align:top}
form h4{margin:0 0 8px}input,select,button{font-size:.9rem;padding:4px 6px;margin:2px;background:#0f1115;color:#e6e6e6;border:1px solid #444;border-radius:4px}
button{background:#238636;border-color:#238636;cursor:pointer}button.warn{background:#9e2f2f;border-color:#9e2f2f}
.muted{color:#888;font-size:.82rem}
</style></head><body>
<h1>🔁 分散式交易 · 兩階段提交（2PC）Demo</h1>
<p>單機模擬：一個<strong>協調者</strong> + 兩個<strong>分片（參與者）</strong>。用「跨分片轉帳」展示原子性 —— A 扣款、B 入帳，<strong>要嘛都成功、要嘛都回滾</strong>。</p>
$banner
<p class="total">全系統總額：<b>$total</b> 元　<span class="muted">（不論 commit/abort，原子性下總額永遠守恆 = 2000）</span></p>

<div class="cards">$shardCards</div>

<h2>協調者決議日誌</h2>
<div class="log">$decHtml</div>

<h2>操作</h2>
<form method="post" action="/api/transfer">
  <h4>① 跨分片轉帳（走 2PC）</h4>
  從 <select name="from_shard"><option>A</option><option>B</option></select>
  <input name="from_acc" value="alice" size="6">
  → <select name="to_shard"><option selected>B</option><option>A</option></select>
  <input name="to_acc" value="bob" size="6"><br>
  金額 <input name="amount" value="200" size="5" type="number">
  <button type="submit">轉帳</button>
  <div class="muted">全 YES → commit；任一 NO → abort 全回滾</div>
</form>

<form method="post" action="/api/fail">
  <h4>② 注入參與者失敗</h4>
  分片 <select name="shard"><option>A</option><option>B</option></select>
  階段 <select name="mode"><option value="">關閉</option><option value="prepare">prepare 投 NO</option><option value="commit">commit 失敗</option></select>
  <button class="warn" type="submit">套用</button>
  <div class="muted">設 prepare→NO 後再轉帳，看 abort 回滾、錢不會少</div>
</form>

<form method="post" action="/api/reset">
  <h4>③ 重設</h4>
  <button class="warn" type="submit">重設帳戶</button>
  <div class="muted">回到 alice=1000、bob=1000</div>
</form>

<p class="muted" style="margin-top:2em">※ 單一 process 模擬協調者 + 參與者；無真實網路 RPC。但 2PC 的 prepare/commit/abort、預寫(WAL)鎖定、投票決議、回滾皆為<strong>真實可執行</strong>邏輯。JSON 看 <a href="/api/state">/api/state</a>。</p>
</body></html>
HTML;
}
