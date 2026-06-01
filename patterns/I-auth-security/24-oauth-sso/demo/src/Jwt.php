<?php
declare(strict_types=1);

/**
 * 極簡 JWT（HS256 風格）— 純原生實作
 * --------------------------------------------------
 * 結構：base64url(header) . base64url(payload) . base64url(signature)
 * 簽章：HMAC-SHA256（hash_hmac，hash 擴充內建）。
 *
 * 誠實聲明：正式 OIDC 多用 RS256（非對稱，openssl 簽）。本機無 openssl，
 * 故以對稱 HS256 取代——簽章/驗章/過期檢查的「邏輯」與真實一致，
 * 差別只在金鑰是「共享密鑰」而非「公私鑰對」。§notes 第 8 段詳述取捨。
 */
final class Jwt
{
    public function __construct(private string $secret) {}

    /** base64url 編碼（自寫：標準 base64 → 換字元 → 去除 padding） */
    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** base64url 解碼（補回 padding → 換字元 → 標準 base64 解） */
    public static function b64urlDecode(string $txt): string
    {
        $pad = strlen($txt) % 4;
        if ($pad > 0) {
            $txt .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode(strtr($txt, '-_', '+/'));
    }

    /**
     * 簽發 JWT。
     * @param array<string,mixed> $claims 業務宣告（sub/scope/...）
     * @param int $ttlSeconds access_token 存活秒數
     */
    public function sign(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = $claims + [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'iss' => 'demo-auth-server',
        ];
        $h = self::b64urlEncode($this->jsonEncode($header));
        $p = self::b64urlEncode($this->jsonEncode($payload));
        $sig = self::b64urlEncode($this->hmac("$h.$p"));
        return "$h.$p.$sig";
    }

    /**
     * 驗章 + 過期檢查，回傳 payload；失敗回 null 並寫入 $error。
     * @param-out string $error
     */
    public function verify(string $token, ?string &$error = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            $error = '格式錯誤（非 header.payload.signature）';
            return null;
        }
        [$h, $p, $sig] = $parts;

        // 1) 驗章：用 hash_equals 做常數時間比較，防時序攻擊
        $expected = self::b64urlEncode($this->hmac("$h.$p"));
        if (!hash_equals($expected, $sig)) {
            $error = '簽章不符（token 被竄改或金鑰不對）';
            return null;
        }

        // 2) 解 payload
        $payload = json_decode(self::b64urlDecode($p), true);
        if (!is_array($payload)) {
            $error = 'payload 非合法 JSON';
            return null;
        }

        // 3) 過期檢查
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            $error = 'token 已過期（exp）';
            return null;
        }

        $error = '';
        return $payload;
    }

    private function hmac(string $data): string
    {
        // hash 擴充內建，回傳 raw binary（第 4 參數 true）
        return hash_hmac('sha256', $data, $this->secret, true);
    }

    private function jsonEncode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
