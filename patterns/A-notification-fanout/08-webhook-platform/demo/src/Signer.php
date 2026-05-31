<?php
declare(strict_types=1);

/**
 * HMAC 簽章器（防偽造 + 防重放）
 * --------------------------------------------------
 * 平台與端點共享一組 secret。每次投遞：
 *   sig = HMAC-SHA256(secret, "{timestamp}.{body}")
 * 把 timestamp 與 sig 放進標頭。接收方：
 *   1) 檢查 timestamp 在容忍視窗內（防重放）
 *   2) 用同一公式重算簽章，並以「常數時間比較」核對（防時序攻擊）
 *
 * 為何把 timestamp 也納入簽章？
 *   若簽章只簽 body，攻擊者攔截到一次合法請求即可無限重放。
 *   納入 timestamp 後，過期的請求會被視窗檢查擋下，重放失效。
 */
final class Signer
{
    /** 接收方容忍的時間偏移（秒）；超出即視為重放/時鐘漂移 */
    private const TOLERANCE_SECONDS = 300;

    /** 平台端：產生簽章標頭 */
    public function sign(string $secret, string $body, ?int $timestamp = null): array
    {
        $ts = $timestamp ?? time();
        $signature = $this->compute($secret, $ts, $body);
        return [
            'X-Webhook-Timestamp' => (string) $ts,
            'X-Webhook-Signature' => 'sha256=' . $signature,
        ];
    }

    /** 接收方：驗證簽章 + 時間視窗。回傳是否通過 */
    public function verify(string $secret, string $body, string $timestampHeader, string $signatureHeader, ?int $now = null): bool
    {
        $now = $now ?? time();
        $ts = (int) $timestampHeader;
        // 1) 防重放：時間戳必須在容忍視窗內
        if ($ts <= 0 || abs($now - $ts) > self::TOLERANCE_SECONDS) {
            return false;
        }
        // 2) 重算並以常數時間比較（避免時序側通道）
        $expected = 'sha256=' . $this->compute($secret, $ts, $body);
        return hash_equals($expected, $signatureHeader);
    }

    private function compute(string $secret, int $timestamp, string $body): string
    {
        // 簽章內容 = "{timestamp}.{body}"，timestamp 一併被簽 → 防重放
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }
}
