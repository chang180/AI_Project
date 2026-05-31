<?php
declare(strict_types=1);

/**
 * 數位錢包 / 支付系統 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8017 -t public
 *
 * 路由：
 *   GET  /                          測試頁（開帳戶、轉帳、列帳本、對帳）
 *   POST /api/accounts              開帳戶 { id, initial }（initial 單位：分）
 *   POST /api/transfers             轉帳 { from, to, amount, key }（amount 單位：分）
 *   GET  /api/accounts/{id}/balance 查餘額
 *   GET  /api/ledger                列出全部分錄（append-only）
 *   GET  /api/reconcile             對帳：驗借貸平衡 + 重算餘額比對物化值
 *   POST /api/reset                 清空 demo 資料
 *
 * 金額一律以「整數分（cents）」表示，避免浮點誤差。
 */

require __DIR__ . '/../src/Ledger.php';
require __DIR__ . '/../src/TransferService.php';

$dataDir = __DIR__ . '/../data';
$ledger  = new Ledger($dataDir);
$svc     = new TransferService($ledger, $dataDir);

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($path, '/') ?: '/';

// 同時支援表單與 JSON 兩種請求體
function input(): array
{
    $data = $_POST;
    $raw = file_get_contents('php://input');
    if ($raw && ($j = json_decode($raw, true)) && is_array($j)) {
        $data = array_merge($data, $j);
    }
    return $data;
}

// ---- POST /api/accounts：開帳戶 ----
if ($method === 'POST' && $path === '/api/accounts') {
    $in = input();
    $id = trim((string) ($in['id'] ?? ''));
    $initial = (int) ($in['initial'] ?? 0);
    if ($id === '') {
        http_response_code(400);
        exit(json_out(['error' => 'id 不可為空']));
    }
    try {
        $ledger->openAccount($id, $initial);
    } catch (Throwable $e) {
        http_response_code(400);
        exit(json_out(['error' => $e->getMessage()]));
    }
    if (wants_html()) { header('Location: /'); exit; }
    exit(json_out(['id' => $id, 'balance' => $ledger->balance($id)]));
}

// ---- POST /api/transfers：轉帳（帶冪等鍵）----
if ($method === 'POST' && $path === '/api/transfers') {
    $in = input();
    $result = $svc->transfer(
        from: trim((string) ($in['from'] ?? '')),
        to: trim((string) ($in['to'] ?? '')),
        amountCents: (int) ($in['amount'] ?? 0),
        idempotencyKey: trim((string) ($in['key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')))
    );
    if (wants_html()) {
        header('Location: /?msg=' . rawurlencode(json_encode($result, JSON_UNESCAPED_UNICODE)));
        exit;
    }
    if (($result['status'] ?? '') === 'rejected' || ($result['status'] ?? '') === 'conflict') {
        http_response_code(($result['status'] === 'conflict') ? 409 : 422);
    }
    exit(json_out($result));
}

// ---- GET /api/accounts/{id}/balance ----
if ($method === 'GET' && preg_match('#^/api/accounts/([^/]+)/balance$#', $path, $m)) {
    $id = urldecode($m[1]);
    if (!$ledger->exists($id)) { http_response_code(404); exit(json_out(['error' => 'not found'])); }
    exit(json_out(['id' => $id, 'balance' => $ledger->balance($id), 'currency' => 'TWD']));
}

// ---- GET /api/ledger：全部分錄 ----
if ($method === 'GET' && $path === '/api/ledger') {
    exit(json_out(['entries' => $ledger->entries()]));
}

// ---- GET /api/reconcile：對帳 ----
if ($method === 'GET' && $path === '/api/reconcile') {
    exit(json_out($ledger->reconcile()));
}

// ---- POST /api/reset：重置 demo ----
if ($method === 'POST' && $path === '/api/reset') {
    foreach (['accounts.json' => '{}', 'ledger.json' => '[]', 'idempotency.json' => '{}', 'txn_seq.txt' => '0'] as $f => $init) {
        @file_put_contents($dataDir . '/' . $f, $init);
    }
    if (wants_html()) { header('Location: /'); exit; }
    exit(json_out(['reset' => true]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($ledger);
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

function wants_html(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
}

/** 分 → 元字串（僅顯示用；內部一律整數分） */
function fmt_money(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $abs = abs($cents);
    return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
}

function render_home(Ledger $ledger): void
{
    $msg = isset($_GET['msg']) ? json_decode((string) $_GET['msg'], true) : null;
    $banner = '';
    if (is_array($msg)) {
        $status = $msg['status'] ?? '';
        $cls = in_array($status, ['rejected', 'conflict'], true) ? 'warn' : 'tip';
        $label = match ($status) {
            'posted'   => ($msg['idempotent_replay'] ?? false) ? '冪等重送：回放原結果（未重複扣款）' : '轉帳成功',
            'rejected' => '已拒絕：' . ($msg['error'] ?? ''),
            'conflict' => '衝突：' . ($msg['error'] ?? ''),
            default    => '結果',
        };
        $banner = "<div class='box $cls'><span class='label'>$label</span><pre>"
            . htmlspecialchars(json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
            . "</pre></div>";
    }

    // 帳戶列
    $acctRows = '';
    foreach ($ledger->accounts() as $id => $a) {
        $idEsc = htmlspecialchars((string) $id);
        $acctRows .= "<tr><td><code>$idEsc</code></td><td>" . fmt_money((int) $a['balance'])
            . "</td><td>" . (int) $a['balance'] . " 分</td></tr>";
    }

    // 帳本分錄
    $entRows = '';
    foreach ($ledger->entries() as $e) {
        $dir = $e['direction'] === 'D' ? '借 D' : '貸 C';
        $entRows .= "<tr><td>{$e['entry_id']}</td><td><code>" . htmlspecialchars((string) $e['transfer_id'])
            . "</code></td><td><code>" . htmlspecialchars((string) $e['account_id']) . "</code></td>"
            . "<td>$dir</td><td>" . fmt_money((int) $e['amount']) . "</td></tr>";
    }

    // 對帳結果
    $rec = $ledger->reconcile();
    $balanced = $rec['balanced'] ? '✅ 借貸平衡' : '❌ 不平衡';
    $mism = empty($rec['mismatches']) ? '✅ 物化餘額 = 重算餘額（無差異）' : '❌ 有差異：' . htmlspecialchars(json_encode($rec['mismatches'], JSON_UNESCAPED_UNICODE));

    // 帳戶選項（給轉帳下拉用）
    $opts = '';
    foreach (array_keys($ledger->accounts()) as $id) {
        $idEsc = htmlspecialchars((string) $id);
        $opts .= "<option value=\"$idEsc\">$idEsc</option>";
    }
    $defaultKey = htmlspecialchars(bin2hex(random_bytes(6)));

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>數位錢包 / 支付系統 · Demo</title>
<style>
  :root{--bg:#0f1419;--soft:#1a2027;--card:#1e2630;--bd:#2a3540;--tx:#e6edf3;--dim:#9aa7b3;--acc:#58a6ff;--acc2:#7ee787;--warn:#f0883e}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--tx);font-family:"Segoe UI","Microsoft JhengHei",system-ui,sans-serif;line-height:1.6}
  .wrap{max-width:900px;margin:0 auto;padding:28px 20px 80px}
  h1{font-size:1.6rem;margin:.2em 0}h2{font-size:1.15rem;border-bottom:1px solid var(--bd);padding-bottom:.3em;margin-top:1.6em}
  a{color:var(--acc);text-decoration:none}
  code{background:#161b22;color:var(--acc2);padding:2px 6px;border-radius:5px;font-size:.88em}
  pre{background:#161b22;border:1px solid var(--bd);border-radius:10px;padding:12px;overflow-x:auto;font-size:.82rem}
  table{width:100%;border-collapse:collapse;margin:.6em 0;font-size:.9rem}
  th,td{border:1px solid var(--bd);padding:8px 10px;text-align:left}th{background:var(--soft);color:var(--acc)}
  .box{border-left:4px solid var(--acc);background:var(--soft);padding:10px 14px;border-radius:0 8px 8px 0;margin:1em 0}
  .box.tip{border-color:var(--acc2)}.box.warn{border-color:var(--warn)}.box .label{font-weight:700;display:block;margin-bottom:.2em}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:680px){.grid{grid-template-columns:1fr}}
  .card{background:var(--card);border:1px solid var(--bd);border-radius:10px;padding:14px 16px}
  input,select,button{font-size:.95rem;padding:6px 8px;border-radius:6px;border:1px solid var(--bd);background:#0d1117;color:var(--tx);margin:3px 0}
  button{background:var(--acc);color:#06121f;border:none;font-weight:700;cursor:pointer}
  label{display:block;color:var(--dim);font-size:.82rem;margin-top:6px}
  .hint{color:var(--dim);font-size:.82rem}
</style></head><body><div class="wrap">
<h1>💳 數位錢包 / 支付系統 · Demo</h1>
<p class="hint">聚焦：<strong>雙分錄帳本（append-only）＋ 冪等付款（重送不重扣）＋ 條件更新防透支</strong>。金額一律以整數分計，避免浮點誤差。</p>
$banner

<div class="grid">
  <div class="card">
    <h2 style="margin-top:0">開帳戶</h2>
    <form method="post" action="/api/accounts">
      <label>帳戶 ID</label><input name="id" placeholder="acct_A" required>
      <label>初始餘額（分，整數）</label><input name="initial" type="number" value="100000" min="0">
      <br><button type="submit">開帳戶</button>
    </form>
    <p class="hint">100000 分 = 1000.00 元。初始入帳也記為雙分錄（金庫 → 帳戶）。</p>
  </div>

  <div class="card">
    <h2 style="margin-top:0">轉帳（帶冪等鍵）</h2>
    <form method="post" action="/api/transfers">
      <label>從</label><select name="from">$opts</select>
      <label>到</label><select name="to">$opts</select>
      <label>金額（分，整數）</label><input name="amount" type="number" value="1500" min="1">
      <label>idempotency key（同鍵重送不重扣）</label><input name="key" value="$defaultKey">
      <br><button type="submit">轉帳</button>
    </form>
    <p class="hint">用<strong>同一個 key</strong>再送一次 → 回放原結果、不重複扣款。轉超過餘額 → 被拒（防透支）。</p>
  </div>
</div>

<h2>帳戶餘額（物化）</h2>
<table><tr><th>帳戶</th><th>餘額（元）</th><th>餘額（分）</th></tr>$acctRows</table>

<h2>帳本分錄（append-only · 真相來源）</h2>
<table><tr><th>#</th><th>transfer_id</th><th>帳戶</th><th>方向</th><th>金額（元）</th></tr>$entRows</table>

<h2>對帳 reconciliation</h2>
<div class="box tip">
  <span class="label">$balanced</span>
  Σ借 = {$rec['total_debit']} 分　Σ貸 = {$rec['total_credit']} 分（雙分錄守恆 → 兩者應相等）<br>
  $mism
</div>
<p><a href="/api/ledger" target="_blank">原始分錄 JSON</a> · <a href="/api/reconcile" target="_blank">對帳 JSON</a></p>

<form method="post" action="/api/reset" onsubmit="return confirm('清空所有 demo 資料？')">
  <button type="submit" style="background:var(--warn)">重置 Demo 資料</button>
</form>

<p class="hint" style="margin-top:1.5em">※ JSON 檔 + 檔案鎖模擬「DB 交易 / 同帳戶序列化」屬示意；雙分錄記帳、借貸恆等、append-only、冪等去重、條件更新防透支/防雙花、餘額守恆檢查為真實邏輯。</p>
</div></body></html>
HTML;
}
