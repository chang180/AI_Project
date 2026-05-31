<?php
declare(strict_types=1);

/**
 * 帳本 / 錢包（Ledger + Wallet）
 * --------------------------------------------------
 * 對應真實元件：帳本資料庫（雙式記帳）+ 錢包服務。
 *
 * 一致性核心（本題考點）：
 *  1. 下單前「凍結保證金」：把可用餘額 available 移到凍結 held，避免同一筆錢被重複下單（防雙花）。
 *  2. 成交時「原子過帳」：扣凍結、增/減持倉、對手方反向過帳，必須一次到位（全成功或全失敗）。
 *  3. 「防超額 / 餘額為負」：扣款一律走條件更新（UPDATE ... WHERE available >= amount），
 *     條件不成立就拒絕，餘額永不為負。
 *
 * 真實系統會用關聯式 DB 交易（BEGIN ... COMMIT）+ 條件 UPDATE +（高衝突時）SELECT FOR UPDATE
 * 或樂觀鎖 version 來保證。這裡用 JSON 檔 + 檔案鎖模擬「一個交易內的原子過帳」。
 *
 * 帳戶結構：
 *   cash.available  可用現金（USDC 示意）
 *   cash.held       已凍結現金（掛單尚未成交的保證金）
 *   positions[YES]  持有的 YES 股份數
 *   positions[NO]   持有的 NO 股份數
 */
final class Ledger
{
    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/ledger.json';
        if (!file_exists($this->file)) {
            // 預設兩個交易者：各 100 元可用現金，並各持 50 股 YES（方便 demo 直接示範賣單撮合；
            // 真實系統的股份來自「以 $1 鑄造一組 1 YES + 1 NO」的完整集合，此處為簡化）。
            $seed = [
                'alice' => ['available' => 100.0, 'held' => 0.0, 'YES' => 50.0, 'NO' => 0.0],
                'bob'   => ['available' => 100.0, 'held' => 0.0, 'YES' => 50.0, 'NO' => 0.0],
            ];
            file_put_contents($this->file, $this->encode($seed));
        }
    }

    /**
     * 凍結保證金（下單前）：條件更新 — 可用餘額足夠才凍結，否則拒絕。
     * 回傳 true=凍結成功；false=餘額不足（防超額）。
     * 這對應 SQL：UPDATE accounts SET available=available-?, held=held+?
     *             WHERE user=? AND available >= ?   （受影響列數=0 → 餘額不足）
     */
    public function hold(string $user, float $amount): bool
    {
        return $this->mutate(function (array &$acc) use ($user, $amount): bool {
            $a = &$acc[$user];
            if (!$a || $a['available'] + 1e-9 < $amount) {
                return false;                 // 條件不成立 → 拒絕，餘額永不為負
            }
            $a['available'] -= $amount;
            $a['held']      += $amount;
            return true;
        });
    }

    /** 釋放凍結（撤單 / 未成交部分退回）。 */
    public function release(string $user, float $amount): void
    {
        $this->mutate(function (array &$acc) use ($user, $amount): bool {
            $a = &$acc[$user];
            $move = min($amount, $a['held']);
            $a['held']      -= $move;
            $a['available'] += $move;
            return true;
        });
    }

    /**
     * 成交原子過帳：買方付凍結現金換得股份；賣方交出股份換得現金。
     * 整段在「同一個檔案鎖（= 同一個 DB 交易）」內完成，全成功或全不動。
     *
     * @param string $buyer  買方
     * @param string $seller 賣方
     * @param string $outcome 'YES' 或 'NO'
     * @param float  $qty    成交股數
     * @param float  $price  成交價（0~1，代表機率）
     */
    public function settleTrade(string $buyer, string $seller, string $outcome, float $qty, float $price): void
    {
        $cost = $qty * $price;   // 這筆成交的現金金額
        $this->mutate(function (array &$acc) use ($buyer, $seller, $outcome, $qty, $cost): bool {
            // 買方：扣凍結現金（下單時 hold() 凍結的保證金）、增持倉
            $acc[$buyer]['held']      -= $cost;
            $acc[$buyer][$outcome]    += $qty;
            // 賣方：交出「已凍結的股份」（下單時 freezeShares() 移入 held_<outcome>）、得現金
            $acc[$seller]['held_' . $outcome] = ($acc[$seller]['held_' . $outcome] ?? 0.0) - $qty;
            $acc[$seller]['available'] += $cost;
            return true;
        });
    }

    /**
     * 凍結賣方股份（賣單也要先凍結標的，避免同一批股份重複賣出 → 防雙花）。
     * 這裡用負的 held 概念過於複雜，demo 簡化為：賣單直接檢查並扣減持倉到「掛單保留」。
     * 為保持單純，賣方掛單時把股份從 positions 移出記為 heldShares。
     */
    public function freezeShares(string $user, string $outcome, float $qty): bool
    {
        return $this->mutate(function (array &$acc) use ($user, $outcome, $qty): bool {
            $a = &$acc[$user];
            if (($a[$outcome] ?? 0.0) + 1e-9 < $qty) {
                return false;                 // 持倉不足 → 拒絕
            }
            $a[$outcome] -= $qty;
            $a['held_' . $outcome] = ($a['held_' . $outcome] ?? 0.0) + $qty;
            return true;
        });
    }

    /** 釋放凍結股份（賣單撤單 / 未成交退回）。 */
    public function releaseShares(string $user, string $outcome, float $qty): void
    {
        $this->mutate(function (array &$acc) use ($user, $outcome, $qty): bool {
            $a = &$acc[$user];
            $move = min($qty, $a['held_' . $outcome] ?? 0.0);
            $a['held_' . $outcome] = ($a['held_' . $outcome] ?? 0.0) - $move;
            $a[$outcome] += $move;
            return true;
        });
    }

    /**
     * 市場到期結算兌付：獲勝側每股兌現 1 元現金，並把雙方持倉歸零（示意）。
     * 在同一個交易內完成「獲勝側加現金、YES/NO 持倉清零」，保證資金守恆。
     */
    public function settleResolution(string $user, string $winner): void
    {
        $this->mutate(function (array &$acc) use ($user, $winner): bool {
            $a = &$acc[$user];
            $win = (float) ($a[$winner] ?? 0.0);
            $a['available'] += $win;     // 獲勝側每股兌現 1 元
            $a['YES'] = 0.0;             // 市場結束 → 持倉歸零
            $a['NO']  = 0.0;
            return true;
        });
    }

    public function accounts(): array
    {
        return $this->read();
    }

    // ---------- 內部：用檔案鎖模擬「一個 DB 交易」 ----------

    /**
     * 在獨佔鎖內讀出帳本、套用變更函式、寫回。
     * 變更函式回傳 false 代表「條件不成立」→ 不寫回、整段視為未發生（原子性）。
     */
    private function mutate(callable $fn): bool
    {
        $fp = fopen($this->file, 'c+');
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $acc = json_decode($raw ?: '{}', true) ?: [];
        $ok = $fn($acc);
        if ($ok) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $this->encode($acc));
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return $ok;
    }

    private function read(): array
    {
        return json_decode((string) file_get_contents($this->file), true) ?: [];
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
