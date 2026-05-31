<?php
declare(strict_types=1);

/**
 * 委託簿（Order Book）
 * --------------------------------------------------
 * 對應真實元件：交易所每個市場/標的（此 demo 為單一市場的 YES 股份）維護的限價單委託簿。
 *
 * 預測市場語意：
 *  - 標的是 YES 股份；價格 0~1，代表「事件發生的市場機率」。市場到期時 YES=1、NO=0 結算。
 *  - 買 YES（bid）＝看漲；賣 YES（ask）＝看跌。買 NO 等價於賣 YES（demo 只建模 YES 一側以保持單純）。
 *
 * 撮合規則（價格-時間優先 price-time priority）：
 *  - 買單依「價高者先」，同價依「先到者先」。
 *  - 賣單依「價低者先」，同價依「先到者先」。
 *  - 當最高買價 >= 最低賣價 → 成交，成交價取「先掛在簿上那張單」的價格（被動單 maker 價）。
 *  - 支援部分成交：一張大單可吃掉多張對手單，剩餘量留在簿上。
 *
 * 注意：本類別只負責「簿的資料結構與撮合演算法」，不碰資金。
 * 資金的凍結 / 過帳 / 防超額由 MatchingEngine 串接 Ledger 完成（職責分離）。
 */
final class OrderBook
{
    /** @var array<int,array> 買單（bid），尚未成交完的掛單 */
    private array $bids = [];
    /** @var array<int,array> 賣單（ask），尚未成交完的掛單 */
    private array $asks = [];

    /**
     * 嘗試把一張新單與簿上對手單撮合，回傳「本次產生的成交清單」。
     * 撮合後的剩餘量（若有）會掛回簿上。此函式為純運算，不動帳本。
     *
     * @param array $order ['id','user','side'(buy|sell),'price','qty','ts']
     * @return array<int,array> trades：每筆 ['price','qty','buyOrder','sellOrder','maker','taker']
     */
    public function match(array $order): array
    {
        $trades = [];
        $remaining = $order['qty'];

        if ($order['side'] === 'buy') {
            // 買單吃「最低價、同價先到」的賣單
            $this->sortAsks();
            foreach ($this->asks as $i => &$ask) {
                if ($remaining <= 1e-9) break;
                if ($ask['price'] > $order['price'] + 1e-9) break;  // 最低賣價已高於出價 → 無法成交
                $fill = min($remaining, $ask['qty']);
                $trades[] = [
                    'price'     => $ask['price'],   // 成交價取被動單（maker = 簿上的賣單）價格
                    'qty'       => $fill,
                    'buyOrder'  => $order['id'],
                    'sellOrder' => $ask['id'],
                    'buyer'     => $order['user'],
                    'seller'    => $ask['user'],
                ];
                $remaining   -= $fill;
                $ask['qty']  -= $fill;
            }
            unset($ask);
            $this->asks = array_values(array_filter($this->asks, fn($a) => $a['qty'] > 1e-9));
            if ($remaining > 1e-9) {
                $order['qty'] = $remaining;
                $this->bids[] = $order;             // 剩餘量掛回買簿（部分成交）
            }
        } else { // sell
            // 賣單吃「最高價、同價先到」的買單
            $this->sortBids();
            foreach ($this->bids as $i => &$bid) {
                if ($remaining <= 1e-9) break;
                if ($bid['price'] + 1e-9 < $order['price']) break;  // 最高買價已低於要價 → 無法成交
                $fill = min($remaining, $bid['qty']);
                $trades[] = [
                    'price'     => $bid['price'],   // 成交價取被動單（maker = 簿上的買單）價格
                    'qty'       => $fill,
                    'buyOrder'  => $bid['id'],
                    'sellOrder' => $order['id'],
                    'buyer'     => $bid['user'],
                    'seller'    => $order['user'],
                ];
                $remaining   -= $fill;
                $bid['qty']  -= $fill;
            }
            unset($bid);
            $this->bids = array_values(array_filter($this->bids, fn($b) => $b['qty'] > 1e-9));
            if ($remaining > 1e-9) {
                $order['qty'] = $remaining;
                $this->asks[] = $order;             // 剩餘量掛回賣簿（部分成交）
            }
        }

        return $trades;
    }

    /** 買簿快照（價高在前），供顯示。 */
    public function bids(): array
    {
        $this->sortBids();
        return $this->bids;
    }

    /** 賣簿快照（價低在前），供顯示。 */
    public function asks(): array
    {
        $this->sortAsks();
        return $this->asks;
    }

    /** 從事件序列重放，重建委託簿狀態（event sourcing 的好處：可重建）。 */
    public function loadResting(array $bids, array $asks): void
    {
        $this->bids = $bids;
        $this->asks = $asks;
    }

    public function snapshot(): array
    {
        return ['bids' => $this->bids(), 'asks' => $this->asks()];
    }

    // ---------- 價格-時間優先排序 ----------
    private function sortBids(): void
    {
        usort($this->bids, function ($a, $b) {
            if (abs($a['price'] - $b['price']) > 1e-9) {
                return $b['price'] <=> $a['price'];   // 價高者先
            }
            return $a['ts'] <=> $b['ts'];             // 同價先到者先
        });
    }

    private function sortAsks(): void
    {
        usort($this->asks, function ($a, $b) {
            if (abs($a['price'] - $b['price']) > 1e-9) {
                return $a['price'] <=> $b['price'];   // 價低者先
            }
            return $a['ts'] <=> $b['ts'];             // 同價先到者先
        });
    }
}
