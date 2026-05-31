<?php
declare(strict_types=1);

require_once __DIR__ . '/OrderBook.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/EventLog.php';

/**
 * 撮合引擎（Matching Engine）
 * --------------------------------------------------
 * 對應真實元件：交易所的核心撮合引擎。
 *
 * 設計重點（本題考點集合）：
 *  1. 單執行緒序列化（single-threaded serialization）：
 *     所有下單請求進到「同一條序列」逐筆處理，避免並發競態（race condition）。
 *     真實系統（如 LMAX、Polymarket）撮合核心刻意單執行緒 / 單分片序列化，
 *     換取「完全確定性、無鎖、可重放」。這裡用一把全域檔案鎖（engine.lock）
 *     讓每次 submit 互斥，模擬「整個引擎一次只處理一筆單」的語意。
 *  2. 先凍結保證金、再撮合、成交時原子過帳：
 *     - 買單：先用 Ledger->hold() 凍結 price*qty 的現金（條件更新，餘額不足直接拒 → 防超額）。
 *     - 賣單：先用 Ledger->freezeShares() 凍結要賣出的股份（持倉不足直接拒 → 防雙花）。
 *     - 撮合產生成交後，逐筆 settleTrade() 原子過帳（扣凍結、轉持倉/現金）。
 *  3. 事件溯源：每個關鍵動作都 append 進 EventLog → 可重放、可審計、可對帳。
 *
 * 委託簿狀態持久化在 data/book.json，使引擎可跨請求（跨 PHP 程序）保留掛單。
 */
final class MatchingEngine
{
    private string $lockFile;
    private string $bookFile;

    public function __construct(
        private string $dataDir,
        private Ledger $ledger,
        private EventLog $log
    ) {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->lockFile = $dataDir . '/engine.lock';
        $this->bookFile = $dataDir . '/book.json';
        if (!file_exists($this->lockFile)) {
            file_put_contents($this->lockFile, '');
        }
        if (!file_exists($this->bookFile)) {
            file_put_contents($this->bookFile, json_encode(['bids' => [], 'asks' => [], 'nextId' => 1]));
        }
    }

    /**
     * 提交一張限價單。整個流程在全域鎖內序列化執行（單執行緒語意）。
     *
     * @return array 結果：['ok'=>bool, 'reason'=>?string, 'orderId'=>?int, 'trades'=>array]
     */
    public function submit(string $user, string $side, float $price, float $qty): array
    {
        if ($side !== 'buy' && $side !== 'sell') {
            return ['ok' => false, 'reason' => 'side 必須是 buy 或 sell', 'trades' => []];
        }
        if ($price <= 0 || $price >= 1) {
            return ['ok' => false, 'reason' => '價格須在 0~1 之間（代表機率）', 'trades' => []];
        }
        if ($qty <= 0) {
            return ['ok' => false, 'reason' => '數量須為正', 'trades' => []];
        }

        // ===== 進入全域序列化區（一次只處理一筆單）=====
        $glock = fopen($this->lockFile, 'c+');
        flock($glock, LOCK_EX);
        try {
            // 1) 先凍結資金 / 股份（防超額、防雙花）。失敗即拒絕，不進簿、不撮合。
            if ($side === 'buy') {
                $need = $price * $qty;                        // 買單最壞情況需要的保證金
                if (!$this->ledger->hold($user, $need)) {
                    $this->log->append('ORDER_REJECTED', [
                        'user' => $user, 'side' => $side, 'price' => $price, 'qty' => $qty,
                        'reason' => 'INSUFFICIENT_FUNDS',
                    ]);
                    return ['ok' => false, 'reason' => '餘額不足，下單被拒（防超額）', 'trades' => []];
                }
            } else {
                if (!$this->ledger->freezeShares($user, 'YES', $qty)) {
                    $this->log->append('ORDER_REJECTED', [
                        'user' => $user, 'side' => $side, 'price' => $price, 'qty' => $qty,
                        'reason' => 'INSUFFICIENT_SHARES',
                    ]);
                    return ['ok' => false, 'reason' => '持倉不足，賣單被拒（防雙花）', 'trades' => []];
                }
            }

            // 2) 載入委託簿、配發訂單 ID、記錄下單事件
            $state = $this->readBook();
            $book = new OrderBook();
            $book->loadResting($state['bids'], $state['asks']);

            $orderId = $state['nextId']++;
            $order = [
                'id'    => $orderId,
                'user'  => $user,
                'side'  => $side,
                'price' => $price,
                'qty'   => $qty,
                'ts'    => microtime(true),
            ];
            $this->log->append('ORDER_PLACED', $order);

            // 3) 撮合（純運算，產生成交清單）
            $trades = $book->match($order);

            // 4) 逐筆成交原子過帳 + 寫成交事件
            foreach ($trades as $t) {
                $this->ledger->settleTrade($t['buyer'], $t['seller'], 'YES', $t['qty'], $t['price']);
                $this->log->append('TRADE_EXECUTED', $t);
                $this->recordTrade($t);

                // 成交價可能優於買方出價（被動賣單更便宜）→ 退回多凍結的保證金給買方
                if ($side === 'buy' && $t['buyer'] === $user) {
                    $refund = ($price - $t['price']) * $t['qty'];
                    if ($refund > 1e-9) {
                        $this->ledger->release($user, $refund);
                    }
                }
            }

            // 5) 把（含剩餘掛單的）委託簿寫回持久化
            $snap = $book->snapshot();
            $this->writeBook(['bids' => $snap['bids'], 'asks' => $snap['asks'], 'nextId' => $state['nextId']]);

            return ['ok' => true, 'orderId' => $orderId, 'trades' => $trades, 'reason' => null];
        } finally {
            flock($glock, LOCK_UN);
            fclose($glock);
        }
        // ===== 離開序列化區 =====
    }

    /** 市場到期結算：贏的一側每股值 1，輸的一側值 0（示意）。 */
    public function resolve(string $winner): array
    {
        $glock = fopen($this->lockFile, 'c+');
        flock($glock, LOCK_EX);
        try {
            $this->log->append('MARKET_RESOLVED', ['winner' => $winner]);
            // 結算邏輯（示意）：持有獲勝側股份的人，每股兌現 1 元現金。
            $accounts = $this->ledger->accounts();
            foreach ($accounts as $user => $a) {
                $shares = (float) ($a[$winner] ?? 0.0);
                if ($shares > 1e-9) {
                    $this->ledger->settleResolution($user, $winner);
                    $this->log->append('PAYOUT', ['user' => $user, 'outcome' => $winner, 'shares' => $shares]);
                }
            }
            return ['ok' => true, 'winner' => $winner];
        } finally {
            flock($glock, LOCK_UN);
            fclose($glock);
        }
    }

    public function book(): array
    {
        $s = $this->readBook();
        return ['bids' => $s['bids'], 'asks' => $s['asks']];
    }

    public function recentTrades(int $n = 20): array
    {
        $file = $this->dataDir . '/trades.json';
        if (!file_exists($file)) return [];
        $all = json_decode((string) file_get_contents($file), true) ?: [];
        return array_slice($all, max(0, count($all) - $n));
    }

    // ---------- 內部持久化 ----------
    private function readBook(): array
    {
        $s = json_decode((string) file_get_contents($this->bookFile), true) ?: [];
        return ['bids' => $s['bids'] ?? [], 'asks' => $s['asks'] ?? [], 'nextId' => $s['nextId'] ?? 1];
    }

    private function writeBook(array $state): void
    {
        file_put_contents($this->bookFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function recordTrade(array $t): void
    {
        $file = $this->dataDir . '/trades.json';
        $all = file_exists($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $t['ts'] = gmdate('c');
        $all[] = $t;
        file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
