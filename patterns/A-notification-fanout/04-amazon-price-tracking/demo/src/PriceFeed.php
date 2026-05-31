<?php
declare(strict_types=1);

/**
 * 價格擷取層（模擬價格串流 / 分層輪詢 + 變更偵測）
 * --------------------------------------------------
 * 真實系統：能訂閱就走事件驅動（CDC，監聽 DB binlog）；否則對外部來源分層輪詢
 *   （hot 每分鐘 / warm 每小時 / cold 每日）。輪詢到的價格先過「變更偵測」，
 *   與上次比對，沒變就丟棄，下游只處理真實變動。
 *
 * 這裡用 JSON 檔保存每個 sku 的現價，提供兩種模擬：
 *   - push()：手動推送一筆指定新價格。
 *   - randomTick()：對所有已知 sku 隨機波動一輪（模擬一次輪詢）。
 * 兩者都回傳「真的變了」的事件清單（changed-only），示意變更偵測去重。
 */
final class PriceFeed
{
    private string $file;
    /** @var array<string,float> sku => 現價 */
    private array $prices;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/prices.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, '{}');
        }
        $this->prices = array_map('floatval', json_decode((string) file_get_contents($this->file), true) ?: []);
    }

    public function priceOf(string $sku): ?float
    {
        return $this->prices[$sku] ?? null;
    }

    /** @return array<string,float> */
    public function all(): array
    {
        return $this->prices;
    }

    /**
     * 推送一筆新價格。內含「變更偵測」：若價格與上次相同（在 0.001 容差內），
     * 視為沒變、回傳 null（事件被丟棄），下游不會被無效流量灌爆。
     *
     * @return array{sku:string,price:float,prev:?float}|null 真的變了才回事件
     */
    public function push(string $sku, float $price): ?array
    {
        $prev = $this->prices[$sku] ?? null;
        if ($prev !== null && abs($prev - $price) < 0.001) {
            return null; // 變更偵測：沒變 → 丟棄
        }
        $this->prices[$sku] = $price;
        $this->persist();
        return ['sku' => $sku, 'price' => $price, 'prev' => $prev];
    }

    /**
     * 模擬一次輪詢：對所有已知 sku 隨機波動 -8% ~ +5%（偏向下跌，方便觀察觸發）。
     * 只回傳「真的變了」的事件（這裡隨機波動幾乎都會變，但流程與真實一致）。
     *
     * @param array<int,string> $skus 要波動的 sku（通常 = 追蹤清單裡的 sku）
     * @return array<int,array{sku:string,price:float,prev:?float}>
     */
    public function randomTick(array $skus): array
    {
        $events = [];
        foreach ($skus as $sku) {
            $base = $this->prices[$sku] ?? 1000.0;
            $factor = 1 + (random_int(-80, 50) / 1000); // -8% ~ +5%
            $next = round(max(1.0, $base * $factor), 2);
            $event = $this->push($sku, $next);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        return $events;
    }

    /** 確保某 sku 有初始價（供尚未有價格的追蹤建立基準） */
    public function ensure(string $sku, float $price): void
    {
        if (!isset($this->prices[$sku])) {
            $this->prices[$sku] = $price;
            $this->persist();
        }
    }

    private function persist(): void
    {
        file_put_contents(
            $this->file,
            json_encode($this->prices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
