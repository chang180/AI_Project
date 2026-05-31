<?php
declare(strict_types=1);

/**
 * 點擊欺詐過濾器（對應真實系統的 即時風控 / bot 過濾）
 * --------------------------------------------------------------------
 * 廣告計費最大的敵人是「點擊欺詐」：同一 IP / 裝置在極短時間內製造大量
 * 點擊（人為刷量、bot 農場、競爭對手惡意消耗廣告預算）。這些點擊雖然
 * click_id 各不相同（騙得過冪等去重），但行為模式異常，不應計費。
 *
 * 本過濾器採用最常見的**速率門檻法（rate limiting / velocity check）**：
 * 在一個短的滑動視窗（windowSec，如 10 秒）內，同一個 IP（可換成
 * 裝置指紋）的點擊數一旦超過門檻（threshold），後續同 IP 點擊即判為
 * 可疑，剔除不計費。
 *
 * 真實系統還會疊加：黑名單 IP/UA、裝置指紋、點擊到轉換的時間分布、
 * 機器學習評分等多層信號；這裡示範最核心、最常被面試問到的一層。
 */
final class FraudFilter
{
    /** @var array<string,int[]> ip => 該 IP 近期通過的點擊時間戳（秒） */
    private array $history = [];

    private int $windowSec;   // 滑動視窗長度（秒）
    private int $threshold;   // 視窗內同 IP 允許的最大點擊數
    private int $passed = 0;  // 判為正常、計費的點擊數
    private int $blocked = 0; // 判為欺詐、剔除的點擊數

    public function __construct(int $windowSec = 10, int $threshold = 5)
    {
        $this->windowSec = $windowSec;
        $this->threshold = $threshold;
    }

    /**
     * 判斷單一（已去重的）點擊事件是否合法。
     * true = 正常可計費；false = 可疑欺詐、剔除。
     */
    public function isLegit(array $event): bool
    {
        $ip = (string) ($event['ip'] ?? '');
        $ts = (int) ($event['ts'] ?? 0);

        // 取出該 IP 視窗內仍有效的點擊時間，丟掉超出視窗的舊紀錄（滑動視窗）
        $recent = [];
        foreach ($this->history[$ip] ?? [] as $t) {
            if ($t > $ts - $this->windowSec) {
                $recent[] = $t;
            }
        }

        // 視窗內已達門檻 → 這一筆判為洪水點擊，剔除（且不計入歷史，避免無限膨脹）
        if (count($recent) >= $this->threshold) {
            $this->history[$ip] = $recent;
            $this->blocked++;
            return false;
        }

        // 正常：記錄這次點擊時間，通過
        $recent[] = $ts;
        $this->history[$ip] = $recent;
        $this->passed++;
        return true;
    }

    public function windowSec(): int
    {
        return $this->windowSec;
    }

    public function threshold(): int
    {
        return $this->threshold;
    }

    public function passedCount(): int
    {
        return $this->passed;
    }

    public function blockedCount(): int
    {
        return $this->blocked;
    }

    public function reset(): void
    {
        $this->history = [];
        $this->passed = 0;
        $this->blocked = 0;
    }
}
