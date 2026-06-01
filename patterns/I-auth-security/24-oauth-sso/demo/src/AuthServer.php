<?php
declare(strict_types=1);

require_once __DIR__ . '/Jwt.php';

/**
 * Authorization Server（授權伺服器）— OAuth 2.0 Authorization Code 流程核心
 * --------------------------------------------------------------------------
 * 負責：client 註冊表、發授權碼 code、code 換 token、refresh 換新 token、撤銷。
 * 狀態（auth_codes / refresh_tokens / 撤銷名單）以 JSON 檔存於 data/，模擬 DB。
 *
 * 真實系統：這些表在分片式 DB / Redis；access_token 是無狀態 JWT（不入庫），
 * refresh_token 與撤銷名單才需持久化（§notes 第 6 段「撤銷難題」）。
 */
final class AuthServer
{
    private string $codesFile;
    private string $refreshFile;
    private string $revokedFile;
    private Jwt $jwt;

    /** access_token 存活：故意設短，以「短期 access + 撤銷名單」解決無狀態 JWT 撤銷難題 */
    public const ACCESS_TTL  = 900;        // 15 分鐘（demo 可調更短以示範過期）
    /** 授權碼存活：極短，一次性 */
    public const CODE_TTL    = 60;         // 60 秒
    public const REFRESH_TTL = 1209600;    // 14 天

    /** client 註冊表（真實系統存 DB；secret 應雜湊存放，這裡為教學明文） */
    private const CLIENTS = [
        'demo-web' => [
            'client_secret' => 's3cr3t-demo-web',
            'redirect_uri'  => 'http://localhost:8024/callback',
            'scopes'        => ['openid', 'profile', 'email'],
        ],
    ];

    /** 模擬已登入並同意的使用者（真實系統來自 session / 登入頁） */
    private const USER = [
        'sub'   => 'user-1001',
        'name'  => 'Alice 王',
        'email' => 'alice@example.com',
    ];

    public function __construct(string $dataDir, string $jwtSecret)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->codesFile   = $dataDir . '/auth_codes.json';
        $this->refreshFile = $dataDir . '/refresh_tokens.json';
        $this->revokedFile = $dataDir . '/revoked.json';
        foreach ([$this->codesFile, $this->refreshFile, $this->revokedFile] as $f) {
            if (!file_exists($f)) {
                file_put_contents($f, '{}');
            }
        }
        $this->jwt = new Jwt($jwtSecret);
    }

    public function jwt(): Jwt
    {
        return $this->jwt;
    }

    /** 取得 client 設定，找不到回 null */
    public function client(string $clientId): ?array
    {
        return self::CLIENTS[$clientId] ?? null;
    }

    // ======================================================================
    //  /authorize：驗 client → 模擬同意 → 簽發一次性授權碼
    // ======================================================================
    /**
     * @param string[] $requestedScopes
     * @return array{ok:bool,code?:string,scope?:string,error?:string}
     */
    public function authorize(string $clientId, string $redirectUri, array $requestedScopes): array
    {
        $client = $this->client($clientId);
        if ($client === null) {
            return ['ok' => false, 'error' => 'unknown client_id'];
        }
        if ($redirectUri !== $client['redirect_uri']) {
            // redirect_uri 必須與註冊值完全一致，否則授權碼可能被導去攻擊者
            return ['ok' => false, 'error' => 'redirect_uri 不符註冊值'];
        }
        // scope 必須是允許子集
        foreach ($requestedScopes as $s) {
            if (!in_array($s, $client['scopes'], true)) {
                return ['ok' => false, 'error' => "不允許的 scope: $s"];
            }
        }
        $scope = implode(' ', $requestedScopes ?: $client['scopes']);

        // 模擬：使用者已登入且按下「同意」→ 簽發授權碼
        $code = $this->randomToken('ac_');
        $codes = $this->read($this->codesFile);
        $codes[$code] = [
            'client_id'    => $clientId,
            'redirect_uri' => $redirectUri,
            'scope'        => $scope,
            'sub'          => self::USER['sub'],
            'exp'          => time() + self::CODE_TTL,
            'used'         => false,               // 一次性：用過即 true
        ];
        $this->write($this->codesFile, $codes);

        return ['ok' => true, 'code' => $code, 'scope' => $scope];
    }

    // ======================================================================
    //  /token (grant_type=authorization_code)：code 換 access + refresh
    // ======================================================================
    /**
     * @return array{ok:bool,error?:string,access_token?:string,refresh_token?:string,token_type?:string,expires_in?:int,scope?:string}
     */
    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri): array
    {
        $client = $this->client($clientId);
        if ($client === null || !hash_equals($client['client_secret'], $clientSecret)) {
            return ['ok' => false, 'error' => 'invalid_client（client_secret 不符）'];
        }

        $codes = $this->read($this->codesFile);
        $entry = $codes[$code] ?? null;
        if ($entry === null) {
            return ['ok' => false, 'error' => 'invalid_grant（授權碼不存在）'];
        }
        // 一次性：重放偵測。被重複使用是攻擊訊號 → 真實系統應撤銷該 code 已發出的所有 token
        if ($entry['used'] === true) {
            return ['ok' => false, 'error' => 'invalid_grant（授權碼已被使用，疑似重放攻擊）'];
        }
        if (time() >= (int) $entry['exp']) {
            return ['ok' => false, 'error' => 'invalid_grant（授權碼已過期）'];
        }
        if ($entry['client_id'] !== $clientId) {
            return ['ok' => false, 'error' => 'invalid_grant（授權碼不屬於此 client）'];
        }
        if ($entry['redirect_uri'] !== $redirectUri) {
            return ['ok' => false, 'error' => 'invalid_grant（redirect_uri 不一致）'];
        }

        // 標記已用（即使後續失敗，code 也不可再用）
        $codes[$code]['used'] = true;
        $this->write($this->codesFile, $codes);

        return $this->issueTokens($clientId, (string) $entry['sub'], (string) $entry['scope']);
    }

    // ======================================================================
    //  /token (grant_type=refresh_token)：refresh 換新 access（+ 輪替 refresh）
    // ======================================================================
    public function refresh(string $clientId, string $clientSecret, string $refreshToken): array
    {
        $client = $this->client($clientId);
        if ($client === null || !hash_equals($client['client_secret'], $clientSecret)) {
            return ['ok' => false, 'error' => 'invalid_client（client_secret 不符）'];
        }
        if ($this->isRevoked($refreshToken)) {
            return ['ok' => false, 'error' => 'invalid_grant（refresh_token 已被撤銷）'];
        }
        $store = $this->read($this->refreshFile);
        $entry = $store[$refreshToken] ?? null;
        if ($entry === null) {
            return ['ok' => false, 'error' => 'invalid_grant（refresh_token 不存在）'];
        }
        if (time() >= (int) $entry['exp']) {
            return ['ok' => false, 'error' => 'invalid_grant（refresh_token 已過期）'];
        }
        if ($entry['client_id'] !== $clientId) {
            return ['ok' => false, 'error' => 'invalid_grant（refresh_token 不屬於此 client）'];
        }

        // refresh 輪替：舊的撤銷、發新的（偵測被盜用重放）
        $this->revoke($refreshToken);
        return $this->issueTokens($clientId, (string) $entry['sub'], (string) $entry['scope']);
    }

    // ======================================================================
    //  /revoke：把 refresh_token 加入撤銷名單
    // ======================================================================
    public function revoke(string $refreshToken): void
    {
        $revoked = $this->read($this->revokedFile);
        $revoked[$refreshToken] = time();
        $this->write($this->revokedFile, $revoked);
    }

    public function isRevoked(string $refreshToken): bool
    {
        $revoked = $this->read($this->revokedFile);
        return isset($revoked[$refreshToken]);
    }

    // ======================================================================
    //  受保護資源驗證：驗 Bearer JWT（章 + exp）
    // ======================================================================
    /**
     * @return array{ok:bool,error?:string,claims?:array}
     */
    public function verifyAccess(string $bearer): array
    {
        $token = trim(preg_replace('/^Bearer\s+/i', '', $bearer) ?? '');
        if ($token === '') {
            return ['ok' => false, 'error' => '缺少 Bearer token'];
        }
        $claims = $this->jwt->verify($token, $err);
        if ($claims === null) {
            return ['ok' => false, 'error' => $err];
        }
        return ['ok' => true, 'claims' => $claims];
    }

    /** 取使用者資訊（受保護資源回傳內容） */
    public function userInfo(): array
    {
        return self::USER;
    }

    // ---------------------------------------------------------------------
    private function issueTokens(string $clientId, string $sub, string $scope): array
    {
        $access = $this->jwt->sign([
            'sub'       => $sub,
            'scope'     => $scope,
            'client_id' => $clientId,
        ], self::ACCESS_TTL);

        $refresh = $this->randomToken('rt_');
        $store = $this->read($this->refreshFile);
        $store[$refresh] = [
            'client_id' => $clientId,
            'sub'       => $sub,
            'scope'     => $scope,
            'exp'       => time() + self::REFRESH_TTL,
        ];
        $this->write($this->refreshFile, $store);

        return [
            'ok'            => true,
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'scope'         => $scope,
        ];
    }

    /** 不可預測隨機字串（random_bytes 內建；base64url 編碼） */
    private function randomToken(string $prefix): string
    {
        return $prefix . Jwt::b64urlEncode(random_bytes(24));
    }

    private function read(string $file): array
    {
        $json = (string) file_get_contents($file);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function write(string $file, array $data): void
    {
        file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
