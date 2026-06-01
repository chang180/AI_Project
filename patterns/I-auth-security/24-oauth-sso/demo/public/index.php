<?php
declare(strict_types=1);

/**
 * OAuth 2.0 / SSO Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8024 -t public
 *
 * 端點（Authorization Code 流程）：
 *   GET  /                  一鍵示範頁：完整跑 authorize→token→/api/me，並示範三種失敗
 *   GET  /authorize         驗 client/redirect/scope → 模擬同意 → 發一次性授權碼 code
 *   POST /token             grant_type=authorization_code | refresh_token
 *   POST /revoke            撤銷 refresh_token
 *   GET  /api/me            受保護資源：帶 Authorization: Bearer <jwt> → 回使用者資訊
 */

require __DIR__ . '/../src/AuthServer.php';

$server = new AuthServer(__DIR__ . '/../data', 'demo-shared-hmac-secret-please-change');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

// ---- GET /authorize ----
if ($method === 'GET' && $path === '/authorize') {
    $clientId = (string) ($_GET['client_id'] ?? '');
    $redirect = (string) ($_GET['redirect_uri'] ?? '');
    $scope    = trim((string) ($_GET['scope'] ?? ''));
    $scopes   = $scope === '' ? [] : preg_split('/\s+/', $scope);

    $res = $server->authorize($clientId, $redirect, $scopes ?: []);
    if (!$res['ok']) {
        http_response_code(400);
        exit(json_out(['error' => $res['error']]));
    }
    // 真實 OAuth：302 導回 redirect_uri?code=...&state=...
    // demo：直接回 JSON，方便腳本串接（並附上真實會用的 redirect 範例）
    exit(json_out([
        'code'             => $res['code'],
        'scope'            => $res['scope'],
        'redirect_example' => $redirect . '?code=' . $res['code'],
    ]));
}

// ---- POST /token ----
if ($method === 'POST' && $path === '/token') {
    $in = read_body();
    $grant = (string) ($in['grant_type'] ?? '');

    if ($grant === 'authorization_code') {
        $res = $server->exchangeCode(
            (string) ($in['client_id'] ?? ''),
            (string) ($in['client_secret'] ?? ''),
            (string) ($in['code'] ?? ''),
            (string) ($in['redirect_uri'] ?? ''),
        );
    } elseif ($grant === 'refresh_token') {
        $res = $server->refresh(
            (string) ($in['client_id'] ?? ''),
            (string) ($in['client_secret'] ?? ''),
            (string) ($in['refresh_token'] ?? ''),
        );
    } else {
        http_response_code(400);
        exit(json_out(['error' => 'unsupported_grant_type']));
    }

    if (!$res['ok']) {
        http_response_code(400);
        exit(json_out(['error' => $res['error']]));
    }
    unset($res['ok']);
    exit(json_out($res));
}

// ---- POST /revoke ----
if ($method === 'POST' && $path === '/revoke') {
    $in = read_body();
    $rt = (string) ($in['refresh_token'] ?? '');
    if ($rt === '') {
        http_response_code(400);
        exit(json_out(['error' => 'missing refresh_token']));
    }
    $server->revoke($rt);
    // RFC 7009：撤銷端點對成功一律回 200（不洩漏 token 是否存在）
    exit(json_out(['revoked' => true]));
}

// ---- GET /api/me（受保護資源） ----
if ($method === 'GET' && $path === '/api/me') {
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $res = $server->verifyAccess($auth);
    if (!$res['ok']) {
        http_response_code(401);
        header('WWW-Authenticate: Bearer error="invalid_token"');
        exit(json_out(['error' => $res['error']]));
    }
    exit(json_out([
        'authenticated' => true,
        'token_claims'  => $res['claims'],
        'user'          => $server->userInfo(),
    ]));
}

// ---- GET /：一鍵示範頁 ----
if ($method === 'GET' && $path === '/') {
    render_home($server, base_url());
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

/** 同時支援 form-urlencoded 與 JSON body */
function read_body(): array
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
        if ($form) {
            return $form;
        }
    }
    return [];
}

function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8024');
}

/**
 * 一鍵示範：在伺服器端「就地」跑完整 Authorization Code 流程，
 * 不靠外部 HTTP，直接呼叫 AuthServer 方法，把每一步結果列出來，
 * 並示範三種攻防：① 授權碼重放被擋 ② 撤銷後 refresh 失效 ③ 竄改 token 驗章失敗。
 */
function render_home(AuthServer $server, string $base): void
{
    $client      = 'demo-web';
    $secret      = 's3cr3t-demo-web';
    $redirect    = 'http://localhost:8024/callback';
    $scope       = ['openid', 'profile', 'email'];
    $steps       = [];

    // 1) /authorize → 授權碼
    $a = $server->authorize($client, $redirect, $scope);
    $steps[] = step('① GET /authorize（驗 client/redirect/scope → 同意 → 發一次性授權碼）', $a);
    $code = $a['code'] ?? '';

    // 2) /token: code 換 token
    $t = $server->exchangeCode($client, $secret, $code, $redirect);
    $steps[] = step('② POST /token (authorization_code)（驗 secret/redirect → 發 access+refresh）', mask($t));
    $access  = $t['access_token'] ?? '';
    $refresh = $t['refresh_token'] ?? '';

    // 3) /api/me 帶 Bearer
    $me = $server->verifyAccess('Bearer ' . $access);
    $steps[] = step('③ GET /api/me（驗 JWT 章 + 未過期 → 回使用者）', $me);

    // 4) 重放同一個 code → 應被擋
    $replay = $server->exchangeCode($client, $secret, $code, $redirect);
    $steps[] = step('④ 攻防：重放同一授權碼 → 應失敗（一次性）', $replay, !$replay['ok']);

    // 5) refresh 換新 access
    $r = $server->refresh($client, $secret, $refresh);
    $steps[] = step('⑤ POST /token (refresh_token)（換新 access；舊 refresh 輪替作廢）', mask($r));
    $newRefresh = $r['refresh_token'] ?? '';

    // 6) 撤銷 newRefresh → 再 refresh 應失敗
    $server->revoke($newRefresh);
    $afterRevoke = $server->refresh($client, $secret, $newRefresh);
    $steps[] = step('⑥ 攻防：POST /revoke 後再 refresh → 應失敗（撤銷名單）', $afterRevoke, !$afterRevoke['ok']);

    // 7) 竄改 token → 驗章失敗
    $tampered = $access . 'x';
    $bad = $server->verifyAccess('Bearer ' . $tampered);
    $steps[] = step('⑦ 攻防：竄改 access_token → 驗章失敗（HMAC 不符）', $bad, !$bad['ok']);

    $rows = implode("\n", $steps);
    $accShort = htmlspecialchars(substr($access, 0, 48)) . '…';

    echo <<<HTML
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OAuth 2.0 / SSO · Demo</title>
<style>
body{max-width:860px;margin:40px auto;padding:0 16px;background:#0d1117;color:#e6edf3;
 font-family:system-ui,"Microsoft JhengHei",sans-serif;line-height:1.6}
h1{border-bottom:2px solid #1f6feb;padding-bottom:8px}
code{background:#161b22;padding:2px 6px;border-radius:4px;color:#79c0ff;word-break:break-all}
.step{border-left:4px solid #1f6feb;background:#161b22;padding:10px 16px;border-radius:0 8px 8px 0;margin:12px 0}
.step.ok{border-left-color:#3fb950}.step .t{font-weight:700;display:block;margin-bottom:6px}
pre{background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:10px;overflow:auto;font-size:.82rem}
.tag{display:inline-block;background:#1f6feb33;color:#79c0ff;border-radius:4px;padding:1px 8px;font-size:.8rem}
a{color:#79c0ff}
</style></head><body>
<h1>🅸 OAuth 2.0 / SSO · Demo</h1>
<p><span class="tag">Authorization Code 流程</span> 本頁在伺服器端就地跑完整流程，每步列出真實結果，並示範三種攻防。</p>
<p>取得的 access_token（JWT，前 48 字）：<code>$accShort</code></p>
$rows
<h2>手動測端點</h2>
<pre>
# 1) 拿授權碼
curl "$base/authorize?client_id=demo-web&redirect_uri=http://localhost:8024/callback&scope=openid%20profile%20email"

# 2) code 換 token
curl -X POST $base/token -d "grant_type=authorization_code&client_id=demo-web&client_secret=s3cr3t-demo-web&code=<code>&redirect_uri=http://localhost:8024/callback"

# 3) 帶 Bearer 打受保護資源
curl $base/api/me -H "Authorization: Bearer <access_token>"

# 4) refresh 換新 access
curl -X POST $base/token -d "grant_type=refresh_token&client_id=demo-web&client_secret=s3cr3t-demo-web&refresh_token=<refresh_token>"

# 5) 撤銷
curl -X POST $base/revoke -d "refresh_token=<refresh_token>"
</pre>
<p style="color:#8b949e;font-size:.85rem">※ 簽章用 HMAC-SHA256（HS256）取代正式 OIDC 常見的 RS256（本機無 openssl）；
授權碼一次性、token 過期、refresh 輪替、撤銷名單等<strong>授權邏輯為真實可執行</strong>。</p>
</body></html>
HTML;
}

/** 把 token 字串截短以利畫面顯示 */
function mask(array $res): array
{
    foreach (['access_token', 'refresh_token'] as $k) {
        if (isset($res[$k]) && is_string($res[$k])) {
            $res[$k] = substr($res[$k], 0, 32) . '…(' . strlen($res[$k]) . ' 字)';
        }
    }
    return $res;
}

function step(string $title, array $payload, bool $ok = false): string
{
    $cls = $ok ? 'step ok' : 'step';
    $json = htmlspecialchars((string) json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ));
    return "<div class=\"$cls\"><span class=\"t\">$title</span><pre>$json</pre></div>";
}
