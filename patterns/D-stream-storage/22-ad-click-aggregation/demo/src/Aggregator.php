<?php
declare(strict_types=1);

/**
 * 視窗聚合器（對應真實系統的 串流聚合 Flink + OLAP 結果庫）
 * --------------------------------------------------------------------
 * 計費要回答的問題是：「某廣告在每一分鐘有多少次『可計費』點擊？」。
 * 逐筆點擊直接寫進計費庫太貴，所以用**滾動視窗（tumbling window）**：
 * 把時間切成固定長度、不重疊的桶（如每 60 秒一桶），同一桶內同一 ad_id
 * 的可計費點擊累加成一個數字，視窗結束才輸出一筆聚合結果。
 *
 *   window_start = floor(ts / windowSec) * windowSec
 *
 * 聚合結果（ad_id, window_start, count）即是寫入 OLAP（如 ClickHouse /
 * Druid）的計費明細，對外提供「分視窗計數」與「總計費」查詢。
 *
 * 亂序 / 遲到事件：真實串流用 watermark 判定「視窗可以收尾了」。本 demo
 * 直接以事件時間（event time）歸桶，因此即使事件亂序抵達，也會落到正確
 * 的視窗（這正是 event-time 處理優於 processing-time 的地方）。
 */
final class Aggregator
{
    /** @var array<string,array<int,int>> ad_id => [window_start => 可計費點擊數] */
    private array $windows = [];

    private int $windowSec;
    private int $billable = 0; // 累計進聚合的可計費點擊總數

    public function __construct(int $windowSec = 60)
    {
        $this->windowSec = $windowSec;
    }

    /** 計算事件所屬的滾動視窗起點（event-time 歸桶，亂序也正確） */
    public function windowStart(int $ts): int
    {
        return intdiv($ts, $this->windowSec) * $this->windowSec;
    }

    /** 把一筆（已去重、已過風控的）可計費點擊累加進對應視窗 */
    public function add(array $event): void
    {
        $adId = (string) ($event['ad_id'] ?? '');
        $ts = (int) ($event['ts'] ?? 0);
        if ($adId === '') {
            return;
        }
        $ws = $this->windowStart($ts);
        $this->windows[$adId][$ws] = ($this->windows[$adId][$ws] ?? 0) + 1;
        $this->billable++;
    }

    /**
     * 查某廣告各視窗的計數（依視窗起點排序），以及總計費。
     * 回傳：['ad_id'=>..., 'window_sec'=>..., 'windows'=>[ws=>count,...], 'total'=>N]
     */
    public function report(string $adId): array
    {
        $w = $this->windows[$adId] ?? [];
        ksort($w);
        return [
            'ad_id' => $adId,
            'window_sec' => $this->windowSec,
            'windows' => $w,
            'total' => array_sum($w),
        ];
    }

    /** 所有廣告的總計費明細（供報表 / OLAP 視圖） */
    public function reportAll(): array
    {
        $out = [];
        foreach (array_keys($this->windows) as $adId) {
            $out[$adId] = $this->report($adId);
        }
        return $out;
    }

    public function windowSec(): int
    {
        return $this->windowSec;
    }

    public function billableCount(): int
    {
        return $this->billable;
    }

    public function snapshot(): array
    {
        return $this->windows;
    }

    public function load(array $windows): void
    {
        $this->windows = $windows;
    }

    public function reset(): void
    {
        $this->windows = [];
        $this->billable = 0;
    }
}
