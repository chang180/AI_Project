<?php
declare(strict_types=1);

/**
 * 令牌桶（Token Bucket）演算法
 * --------------------------------------------------
 * 真實系統：狀態 { tokens, last_refill } 存 Redis HASH，
 *           讀-算補充-扣減-寫回 用 Lua 腳本原子執行。
 * 這裡：純計算邏輯，狀態由 RateLimiter 用檔案鎖原子持有，本類只做數學。
 *
 * 概念：桶有固定容量 capacity；以 refillRate（個/秒）持續補 token。
 *       每個請求消耗 1 個 token；有 token 則放行，否則拒絕。
 *       「攢著的 token」允許短時突發 → 對 API 用戶友善。
 */
final class TokenBucket
{
    /**
     * @param float $capacity   桶容量（可累積的最大 token 數，即容許的突發量）
     * @param float $refillRate 每秒補充的 token 數（決定平均速率）
     */
    public function __construct(
        private float $capacity,
        private float $refillRate,
    ) {}

    /**
     * 依「現在 - 上次補充時間」補 token，再嘗試消耗 1 個。
     *
     * @param array{tokens:float,last_refill:float}|null $state 現有狀態（null 視為滿桶新建）
     * @param float $now microtime(true)
     * @return array{allowed:bool,state:array{tokens:float,last_refill:float},remaining:int,retry_after:int}
     */
    public function tryConsume(?array $state, float $now): array
    {
        // 首次出現的 key：給滿桶
        $tokens = $state['tokens'] ?? $this->capacity;
        $last   = $state['last_refill'] ?? $now;

        // 依經過時間補 token（不超過容量）
        $elapsed = max(0.0, $now - $last);
        $tokens  = min($this->capacity, $tokens + $elapsed * $this->refillRate);

        if ($tokens >= 1.0) {
            $tokens -= 1.0;          // 消耗一個 → 放行
            $allowed = true;
            $retryAfter = 0;
        } else {
            $allowed = false;        // 沒 token → 拒絕
            // 算還要多久才補滿 1 個 token
            $deficit = 1.0 - $tokens;
            $retryAfter = $this->refillRate > 0
                ? (int) ceil($deficit / $this->refillRate)
                : 1;
        }

        return [
            'allowed'     => $allowed,
            'state'       => ['tokens' => $tokens, 'last_refill' => $now],
            'remaining'   => (int) floor($tokens),
            'retry_after' => $retryAfter,
        ];
    }
}
