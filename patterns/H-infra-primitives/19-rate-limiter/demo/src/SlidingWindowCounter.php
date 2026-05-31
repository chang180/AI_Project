<?php
declare(strict_types=1);

/**
 * 滑動視窗計數器（Sliding Window Counter）演算法
 * --------------------------------------------------
 * 真實系統：當前窗 / 前一窗各一個 Redis 計數器（INCR + EXPIRE），
 *           讀兩個窗 + 加權估計，原子化。
 * 這裡：純計算邏輯，狀態由 RateLimiter 用檔案鎖原子持有。
 *
 * 概念：把時間切成固定長度的窗。裸固定視窗會在「邊界」放行到 2 倍量
 *       （窗末打滿 + 下窗初再打滿）。本演算法用
 *           估計值 = 當前窗計數 + 前一窗計數 × (前一窗尚未滑出的比例)
 *       平滑這個邊界突刺，記憶體只需兩個計數器。
 */
final class SlidingWindowCounter
{
    /**
     * @param int $limit     每個視窗允許的請求數
     * @param int $windowSec 視窗長度（秒）
     */
    public function __construct(
        private int $limit,
        private int $windowSec,
    ) {}

    /**
     * @param array{window_id:int,curr:int,prev:int}|null $state 現有狀態
     * @param float $now microtime(true)
     * @return array{allowed:bool,state:array{window_id:int,curr:int,prev:int},remaining:int,retry_after:int}
     */
    public function tryConsume(?array $state, float $now): array
    {
        $windowId = (int) floor($now / $this->windowSec);   // 當前窗編號
        $curr = $state['curr'] ?? 0;
        $prev = $state['prev'] ?? 0;
        $stateWindow = $state['window_id'] ?? $windowId;

        // 視窗已前進：把舊「當前窗」降為「前一窗」
        if ($windowId === $stateWindow + 1) {
            $prev = $curr;
            $curr = 0;
        } elseif ($windowId > $stateWindow + 1) {
            // 跳過一個以上的窗 → 前面都歸零
            $prev = 0;
            $curr = 0;
        }

        // 前一窗「尚未滑出」的比例：越接近新窗開頭，前一窗權重越高
        $elapsedInWindow = ($now - $windowId * $this->windowSec) / $this->windowSec; // 0~1
        $prevWeight = 1.0 - $elapsedInWindow;

        // 加權估計值
        $estimate = $curr + $prev * $prevWeight;

        if ($estimate < $this->limit) {
            $curr++;                 // 計入當前窗 → 放行
            $allowed = true;
            $retryAfter = 0;
        } else {
            $allowed = false;        // 估計值已達上限 → 拒絕
            // 等到本窗結束、前一窗權重再降即可恢復，簡單回報剩餘秒數
            $retryAfter = (int) ceil(($windowId + 1) * $this->windowSec - $now);
            if ($retryAfter < 1) {
                $retryAfter = 1;
            }
        }

        $remaining = (int) max(0, floor($this->limit - ($curr + $prev * $prevWeight)));

        return [
            'allowed'     => $allowed,
            'state'       => ['window_id' => $windowId, 'curr' => $curr, 'prev' => $prev],
            'remaining'   => $remaining,
            'retry_after' => $retryAfter,
        ];
    }
}
