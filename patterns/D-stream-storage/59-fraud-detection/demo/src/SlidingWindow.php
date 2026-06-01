<?php
declare(strict_types=1);

/**
 * 滑動視窗速度特徵（對應真實系統的 即時線上特徵 / 串流聚合，呼應 ㉒ 廣告點擊聚合）
 * --------------------------------------------------------------------
 * 詐欺偵測最有力的「即時特徵」之一就是**速度（velocity）**：
 * 同一張卡 / 同一個 IP 在「最近一小段時間」內出現了幾筆交易？
 * 盜刷往往是短時間連續嘗試（試卡、掃號），所以「短視窗內筆數暴增」
 * 是強烈訊號。
 *
 * 計算方式：對每個鍵（如 card_id 或 ip）維護一串「最近交易時間戳」，
 * 每次評估時：
 *   1. 丟掉超出視窗（ts - windowSec 以前）的舊紀錄（這就是「滑動」）。
 *   2. 把這一筆加進去。
 *   3. 視窗內目前筆數，即為該鍵此刻的「速度特徵」。
 *
 * 這跟 ㉒ 的 FraudFilter 同源（同 IP 滑動視窗洪水偵測），差別在這裡把
 * 「速度」當成可組合的**特徵值**回傳，交給規則引擎去評分，而不是直接
 * 二元判定通過/剔除。真實系統會把這層放在 Redis / Flink keyed-state，
 * 用 TTL 自動淘汰舊鍵。
 */
final class SlidingWindow
{
    /** @var array<string,int[]> key => 該鍵視窗內的交易時間戳（秒，遞增） */
    private array $history = [];

    private int $windowSec;

    public function __construct(int $windowSec = 60)
    {
        $this->windowSec = $windowSec;
    }

    /**
     * 記錄一筆事件並回傳「記錄後」該鍵在視窗內的筆數（含這一筆）。
     * 這個回傳值就是此刻的速度特徵 = velocity。
     */
    public function hit(string $key, int $ts): int
    {
        $recent = [];
        foreach ($this->history[$key] ?? [] as $t) {
            if ($t > $ts - $this->windowSec) {
                $recent[] = $t;
            }
        }
        $recent[] = $ts;
        $this->history[$key] = $recent;
        return count($recent);
    }

    /** 只看不寫：查某鍵在某時間點視窗內的筆數（不含尚未記錄的當前事件） */
    public function count(string $key, int $ts): int
    {
        $n = 0;
        foreach ($this->history[$key] ?? [] as $t) {
            if ($t > $ts - $this->windowSec) {
                $n++;
            }
        }
        return $n;
    }

    public function windowSec(): int
    {
        return $this->windowSec;
    }

    public function snapshot(): array
    {
        return $this->history;
    }

    public function load(array $history): void
    {
        $this->history = $history;
    }

    public function reset(): void
    {
        $this->history = [];
    }
}
