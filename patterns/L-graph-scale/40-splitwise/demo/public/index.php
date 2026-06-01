<?php
declare(strict_types=1);

/**
 * Splitwise 帳務分攤 Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8040 -t public
 *
 * 路由：
 *   GET  /                  測試頁（建群組成員、加支出、看淨餘額、債務簡化）
 *   POST /api/expense       新增支出 { payer, amount, type, among, desc, key }
 *   GET  /api/balances      每人淨餘額（含守恆驗證 sum=0）
 *   GET  /api/settle        債務簡化結果（最少轉帳筆數）
 *   GET  /api/state         完整狀態（成員 + 支出 + 淨額 + 簡化）
 *   POST /api/members       設定群組成員 { members: [...] }
 *   POST /api/seed          重塞範例資料
 */

require __DIR__ . '/../src/Ledger.php';

$ledger = new Ledger(__DIR__ . '/../data');

// 首次啟動：若帳本空白，塞入範例群組與幾筆支出
if (count($ledger->members()) === 0) {
    seed($ledger);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// ---- POST /api/expense：新增支出 ----
if ($method === 'POST' && $path === '/api/expense') {
    $in = body();
    try {
        $payer  = trim((string)($in['payer'] ?? ''));
        $amount = (int)($in['amount'] ?? 0);          // 整數分
        $type   = (string)($in['type'] ?? 'equal');
        $among  = $in['among'] ?? [];
        $desc   = (string)($in['desc'] ?? '');
        $key    = (string)($in['key'] ?? '');
        $exp = $ledger->addExpense($payer, $amount, $type, $among, $desc, $key);
        exit(json_out(['ok' => true, 'expense' => $exp]));
    } catch (Throwable $e) {
        http_response_code(400);
        exit(json_out(['ok' => false, 'error' => $e->getMessage()]));
    }
}

// ---- POST /api/members：設定群組成員 ----
if ($method === 'POST' && $path === '/api/members') {
    $in = body();
    $members = $in['members'] ?? [];
    if (!is_array($members) || count($members) === 0) {
        http_response_code(400);
        exit(json_out(['ok' => false, 'error' => 'members 不可為空']));
    }
    $ledger->setMembers($members);
    exit(json_out(['ok' => true, 'members' => $ledger->members()]));
}

// ---- GET /api/balances ----
if ($method === 'GET' && $path === '/api/balances') {
    exit(json_out($ledger->balances()));
}

// ---- GET /api/settle ----
if ($method === 'GET' && $path === '/api/settle') {
    exit(json_out($ledger->settle()));
}

// ---- GET /api/state ----
if ($method === 'GET' && $path === '/api/state') {
    exit(json_out($ledger->state()));
}

// ---- POST /api/seed：重塞範例 ----
if ($method === 'POST' && $path === '/api/seed') {
    $ledger->reset();
    seed($ledger);
    exit(json_out(['ok' => true, 'state' => $ledger->state()]));
}

// ---- GET /：測試頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($ledger);
    exit;
}

http_response_code(404);
echo 'not found';

// ============ 輔助函式 ============

function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST;
}

function json_out(array $data): string
{
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** 把整數分格式化成「$xx.xx」 */
function money(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $abs = abs($cents);
    return $sign . '$' . intdiv($abs, 100) . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
}

/** 塞入範例：4 人群組 + 3 筆不同分法支出 */
function seed(Ledger $ledger): void
{
    $ledger->setMembers(['Amy', 'Ben', 'Cat', 'Dan']);
    // 1) 均分：Amy 付晚餐 $120.00，四人均分
    $ledger->addExpense('Amy', 12000, 'equal', ['Amy', 'Ben', 'Cat', 'Dan'], '晚餐（四人均分）', 'seed-1');
    // 2) 指定金額：Ben 付計程車 $60.00，Ben 0、Cat 4000、Dan 2000
    $ledger->addExpense('Ben', 6000, 'exact', ['Ben' => 0, 'Cat' => 4000, 'Dan' => 2000], '計程車（指定金額）', 'seed-2');
    // 3) 百分比：Cat 付住宿 $300.00，Amy40% Ben30% Cat20% Dan10%
    $ledger->addExpense('Cat', 30000, 'percentage', ['Amy' => 40, 'Ben' => 30, 'Cat' => 20, 'Dan' => 10], '住宿（百分比）', 'seed-3');
}

function render_home(Ledger $ledger): void
{
    $st = $ledger->state();
    $members = $st['members'];
    $memberOpts = '';
    foreach ($members as $m) {
        $memberOpts .= "<option value=\"" . htmlspecialchars($m) . "\">" . htmlspecialchars($m) . "</option>";
    }
    $membersJson = json_encode($members, JSON_UNESCAPED_UNICODE);

    // 支出明細
    $expRows = '';
    foreach ($st['expenses'] as $e) {
        $shareStr = [];
        foreach ($e['shares'] as $p => $c) {
            $shareStr[] = htmlspecialchars($p) . ' ' . money($c);
        }
        $expRows .= "<tr><td>{$e['id']}</td>"
            . "<td>" . htmlspecialchars($e['payer']) . "</td>"
            . "<td>" . money($e['amount']) . "</td>"
            . "<td>" . htmlspecialchars($e['type']) . "</td>"
            . "<td>" . htmlspecialchars($e['desc']) . "</td>"
            . "<td style='font-size:.82rem'>" . implode('、', $shareStr) . "</td></tr>";
    }

    // 淨餘額
    $bal = $st['balances'];
    $balRows = '';
    foreach ($bal['net'] as $p => $v) {
        $role = $v > 0 ? "<span style='color:#7ee787'>應收 creditor</span>"
              : ($v < 0 ? "<span style='color:#ff7b72'>應付 debtor</span>" : '已結清');
        $balRows .= "<tr><td>" . htmlspecialchars($p) . "</td><td>" . money($v) . "</td><td>$role</td></tr>";
    }
    $conserved = $bal['conserved']
        ? "<span style='color:#7ee787'>✓ 守恆：全體淨額和 = " . money($bal['sum']) . "（恆為 0）</span>"
        : "<span style='color:#ff7b72'>✗ 守恆失敗：sum = " . money($bal['sum']) . "</span>";

    // 債務簡化
    $set = $st['settle'];
    $setRows = '';
    foreach ($set['transfers'] as $t) {
        $setRows .= "<tr><td>" . htmlspecialchars($t['from']) . "</td>"
            . "<td>→</td><td>" . htmlspecialchars($t['to']) . "</td>"
            . "<td>" . money($t['amount']) . "</td></tr>";
    }
    if ($setRows === '') {
        $setRows = "<tr><td colspan='4'>已全部結清，無需轉帳</td></tr>";
    }

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Splitwise 帳務分攤 · Demo</title>
<style>
body{max-width:860px;margin:32px auto;padding:0 16px;background:#0d1117;color:#e6edf3;
  font-family:system-ui,"Microsoft JhengHei",sans-serif;line-height:1.6}
h1,h2{color:#e6edf3}h2{border-bottom:1px solid #30363d;padding-bottom:6px;margin-top:1.8em}
a{color:#58a6ff}code{background:#161b22;padding:2px 6px;border-radius:4px}
.box{border-left:4px solid #7ee787;background:#161b22;padding:12px 16px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;margin-bottom:4px}
table{width:100%;border-collapse:collapse;margin:.6em 0}
td,th{border:1px solid #30363d;padding:7px 9px;text-align:left}
th{background:#161b22}
form{background:#161b22;padding:14px 16px;border-radius:8px;margin:1em 0}
input,select,button{font-size:1rem;padding:6px 8px;border-radius:6px;border:1px solid #30363d;background:#0d1117;color:#e6edf3}
button{background:#238636;border-color:#238636;cursor:pointer;font-weight:600}
button.alt{background:#1f6feb;border-color:#1f6feb}
label{display:inline-block;margin:4px 8px 4px 0}
.pill{display:inline-block;background:#1f6feb22;border:1px solid #1f6feb;border-radius:999px;padding:2px 10px;margin:2px}
</style>
</head><body>
<h1>💸 Splitwise 帳務分攤 · Demo</h1>
<p>群組記帳 → 三種分攤（均分 / 指定金額 / 百分比）→ 淨額聚合（守恆 sum=0）→ 債務簡化（最小現金流，最少轉帳筆數）。金額以<strong>整數分</strong>處理避免浮點誤差。</p>

<div class="box"><span class="label">群組成員</span>
HTML;
    foreach ($members as $m) {
        echo "<span class='pill'>" . htmlspecialchars($m) . "</span>";
    }
    echo <<<HTML
</div>

<h2>① 新增支出</h2>
<form id="expForm">
  <label>付款人 <select name="payer">$memberOpts</select></label>
  <label>金額 <input name="amount_dollar" type="number" step="0.01" min="0.01" value="50.00" style="width:90px"> 元</label>
  <label>說明 <input name="desc" placeholder="午餐" style="width:120px"></label>
  <br>
  <label>分攤方式
    <select name="type" id="typeSel">
      <option value="equal">均分 equal</option>
      <option value="exact">指定金額 exact</option>
      <option value="percentage">百分比 percentage</option>
    </select>
  </label>
  <div id="amongBox" style="margin-top:8px"></div>
  <p style="font-size:.85rem;color:#8b949e" id="hint"></p>
  <button type="submit">送出支出</button>
  <button type="button" class="alt" id="seedBtn">重塞範例資料</button>
</form>

<h2>② 各人淨餘額</h2>
<p>$conserved</p>
<table><tr><th>成員</th><th>淨餘額</th><th>角色</th></tr>$balRows</table>

<h2>③ 債務簡化（最小現金流）</h2>
<p>簡化前原始欠款邊：<strong>{$set['before']}</strong> 筆　→　簡化後最少轉帳：<strong style="color:#7ee787">{$set['after']}</strong> 筆</p>
<table><tr><th>付款人</th><th></th><th>收款人</th><th>金額</th></tr>$setRows</table>

<h2>④ 支出明細</h2>
<table><tr><th>#</th><th>付款人</th><th>金額</th><th>方式</th><th>說明</th><th>各人分攤</th></tr>$expRows</table>

<p style="color:#8b949e;font-size:.85rem">※ 單機帳本（JSON 檔 + flock）；分攤計算、淨額守恆、債務簡化（最小現金流）皆為真實可執行邏輯。多幣別、分片、強一致帳本為正式系統的擴展議題，見 notes。</p>

<script>
const members = $membersJson;
const typeSel = document.getElementById('typeSel');
const amongBox = document.getElementById('amongBox');
const hint = document.getElementById('hint');

function renderAmong() {
  const t = typeSel.value;
  let html = '';
  if (t === 'equal') {
    hint.textContent = '勾選參與均分的人，金額自動平均（餘數逐分配給前面的人）。';
    members.forEach(m => { html += `<label><input type="checkbox" class="amg" value="\${m}" checked> \${m}</label>`; });
  } else if (t === 'exact') {
    hint.textContent = '輸入每人應分攤的金額（元），總和需等於支出金額。';
    members.forEach(m => { html += `<label>\${m} <input type="number" step="0.01" min="0" class="amg" data-name="\${m}" value="0" style="width:80px"></label>`; });
  } else {
    hint.textContent = '輸入每人百分比（整數），總和需 = 100。';
    members.forEach(m => { html += `<label>\${m} <input type="number" step="1" min="0" class="amg" data-name="\${m}" value="0" style="width:60px">%</label>`; });
  }
  amongBox.innerHTML = html;
}
typeSel.addEventListener('change', renderAmong);
renderAmong();

document.getElementById('expForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const f = ev.target;
  const t = f.type.value;
  const amountCents = Math.round(parseFloat(f.amount_dollar.value) * 100);
  let among;
  if (t === 'equal') {
    among = [...document.querySelectorAll('.amg:checked')].map(x => x.value);
  } else if (t === 'exact') {
    among = {};
    document.querySelectorAll('.amg').forEach(x => { among[x.dataset.name] = Math.round(parseFloat(x.value || '0') * 100); });
  } else {
    among = {};
    document.querySelectorAll('.amg').forEach(x => { among[x.dataset.name] = parseInt(x.value || '0', 10); });
  }
  const payload = { payer: f.payer.value, amount: amountCents, type: t, among, desc: f.desc.value,
                    key: 'web-' + Date.now() };
  const r = await fetch('/api/expense', { method: 'POST', headers: {'Content-Type':'application/json'},
                                          body: JSON.stringify(payload) });
  const j = await r.json();
  if (!j.ok) { alert('錯誤：' + j.error); return; }
  location.reload();
});

document.getElementById('seedBtn').addEventListener('click', async () => {
  await fetch('/api/seed', { method: 'POST' });
  location.reload();
});
</script>
</body></html>
HTML;
}
