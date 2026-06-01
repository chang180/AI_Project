<?php
declare(strict_types=1);

/**
 * Splitwise 帳本核心（模擬帳務服務 + 淨額聚合 + 債務簡化）
 * --------------------------------------------------------------------
 * 真實系統：交易服務寫入「分攤明細」帳本（append-only、強一致），
 *   再由淨額聚合算出每人對群組的淨餘額，最後用最小現金流演算法把
 *   多對多欠款圖化簡成最少筆數的轉帳。
 *
 * 這裡用 JSON 檔當「帳本 DB」，用檔案鎖（flock）保證並發寫入的原子性，
 * 並以「整數分」儲存金額，避免浮點誤差。下列核心邏輯皆為真實可執行：
 *   1. 三種分攤：均分 equal / 指定金額 exact / 百分比 percentage
 *   2. 冪等鍵防重複入帳（idempotency key）
 *   3. 淨額守恆：全體淨額和恆為 0（會主動驗證）
 *   4. 債務簡化（最小現金流）：貪婪結算，最大債主 ↔ 最大欠款人
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
            $this->writeRaw([
                'members'   => [],   // 群組成員名單（字串陣列）
                'expenses'  => [],   // 支出明細（含分攤）
                'seen_keys' => [],   // 已處理的冪等鍵
            ]);
        }
    }

    // ============ 群組成員 ============

    /** 設定群組成員（覆寫整份成員名單） */
    public function setMembers(array $members): void
    {
        $members = array_values(array_unique(array_map('strval', $members)));
        $this->mutate(function (array $db) use ($members): array {
            $db['members'] = $members;
            return $db;
        });
    }

    public function members(): array
    {
        return $this->read()['members'];
    }

    // ============ 新增支出 ============

    /**
     * 新增一筆支出。金額一律以「整數分」處理（cents）。
     *
     * @param string $payer  付款人
     * @param int    $amountCents 總金額（分）
     * @param string $type   分攤方式：equal | exact | percentage
     * @param array  $among  參與分攤的人；
     *                       - equal：人名清單 ['A','B','C']
     *                       - exact：{ 人名 => 該人應分攤的分 }（總和需 = amountCents）
     *                       - percentage：{ 人名 => 百分比 }（總和需 = 100）
     * @param string $desc   說明
     * @param string $idempotencyKey 冪等鍵（同鍵重送不會重複入帳）
     *
     * @return array 該筆支出紀錄（含算好的每人分攤分額）
     */
    public function addExpense(
        string $payer,
        int $amountCents,
        string $type,
        array $among,
        string $desc = '',
        string $idempotencyKey = ''
    ): array {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('金額必須為正整數（分）');
        }

        $result = null;
        $this->mutate(function (array $db) use (
            $payer, $amountCents, $type, $among, $desc, $idempotencyKey, &$result
        ): array {
            // --- 冪等去重：同鍵已處理過 → 直接回傳原紀錄，不重複入帳 ---
            $key = $idempotencyKey !== '' ? $idempotencyKey : null;
            if ($key !== null && isset($db['seen_keys'][$key])) {
                $idx = $db['seen_keys'][$key];
                $result = $db['expenses'][$idx];
                $result['idempotent_replay'] = true;
                return $db; // 不變更
            }

            $members = $db['members'];
            if (!in_array($payer, $members, true)) {
                throw new InvalidArgumentException("付款人 {$payer} 不在群組成員內");
            }

            $shares = $this->computeShares($amountCents, $type, $among, $members);

            $expense = [
                'id'          => count($db['expenses']) + 1,
                'payer'       => $payer,
                'amount'      => $amountCents,
                'type'        => $type,
                'shares'      => $shares,   // { 人名 => 應分攤的分 }
                'desc'        => $desc,
                'created_at'  => gmdate('c'),
            ];
            $idx = count($db['expenses']);
            $db['expenses'][] = $expense;
            if ($key !== null) {
                $db['seen_keys'][$key] = $idx;
            }
            $result = $expense;
            return $db;
        });

        return $result;
    }

    /**
     * 依分攤方式算出「每人應分攤的整數分」。
     * 餘數（不能整除的分）會逐分配給前面的人，確保 sum(shares) === amountCents。
     */
    private function computeShares(int $amount, string $type, array $among, array $members): array
    {
        $shares = [];

        if ($type === 'equal') {
            $people = array_values(array_unique(array_map('strval', $among)));
            if (count($people) === 0) {
                throw new InvalidArgumentException('均分需至少一位參與者');
            }
            foreach ($people as $p) {
                if (!in_array($p, $members, true)) {
                    throw new InvalidArgumentException("{$p} 不在群組成員內");
                }
            }
            $base = intdiv($amount, count($people));
            $rem  = $amount - $base * count($people); // 餘數（分）
            foreach ($people as $i => $p) {
                $shares[$p] = $base + ($i < $rem ? 1 : 0); // 餘數逐分配給前幾人
            }
            return $shares;
        }

        if ($type === 'exact') {
            $sum = 0;
            foreach ($among as $p => $cents) {
                $p = (string) $p;
                $cents = (int) $cents;
                if (!in_array($p, $members, true)) {
                    throw new InvalidArgumentException("{$p} 不在群組成員內");
                }
                $shares[$p] = $cents;
                $sum += $cents;
            }
            if ($sum !== $amount) {
                throw new InvalidArgumentException("exact 分攤總和 {$sum} 分 ≠ 支出 {$amount} 分");
            }
            return $shares;
        }

        if ($type === 'percentage') {
            $pctSum = 0;
            $tmp = [];
            foreach ($among as $p => $pct) {
                $p = (string) $p;
                $pct = (int) $pct;
                if (!in_array($p, $members, true)) {
                    throw new InvalidArgumentException("{$p} 不在群組成員內");
                }
                $tmp[$p] = $pct;
                $pctSum += $pct;
            }
            if ($pctSum !== 100) {
                throw new InvalidArgumentException("percentage 總和 {$pctSum} ≠ 100");
            }
            // 先按百分比下取整，再把因下取整少掉的分逐一補回（最大餘數法的簡化）
            $assigned = 0;
            $people = array_keys($tmp);
            foreach ($people as $p) {
                $shares[$p] = intdiv($amount * $tmp[$p], 100);
                $assigned += $shares[$p];
            }
            $rem = $amount - $assigned;
            for ($i = 0; $i < $rem; $i++) {
                $shares[$people[$i % count($people)]] += 1;
            }
            return $shares;
        }

        throw new InvalidArgumentException("未知分攤方式：{$type}");
    }

    // ============ 淨額計算 ============

    /**
     * 每人對群組的「淨餘額」（整數分）：
     *   net = 我幫大家墊付的總額 − 我自己該分攤的總額
     *   net > 0 → creditor（應收）；net < 0 → debtor（應付）。
     * 守恆：所有人 net 相加恆為 0。
     *
     * @return array{net: array<string,int>, sum: int, conserved: bool}
     */
    public function balances(): array
    {
        $db = $this->read();
        $net = [];
        foreach ($db['members'] as $m) {
            $net[$m] = 0;
        }
        foreach ($db['expenses'] as $e) {
            // 付款人墊付了整筆金額
            $net[$e['payer']] = ($net[$e['payer']] ?? 0) + $e['amount'];
            // 每個參與者欠下自己那份
            foreach ($e['shares'] as $person => $share) {
                $net[$person] = ($net[$person] ?? 0) - $share;
            }
        }
        $sum = 0;
        foreach ($net as $v) {
            $sum += $v;
        }
        return [
            'net'       => $net,
            'sum'       => $sum,        // 守恆驗證：必為 0
            'conserved' => $sum === 0,
        ];
    }

    // ============ 債務簡化（最小現金流） ============

    /**
     * 把多對多欠款圖化簡成最少筆數的轉帳。
     *
     * 貪婪法：每一輪取「最大債主（net 最正）」與「最大欠款人（net 最負）」
     * 互相結算 min(|債|,|欠|)，至少消掉其中一人。N 人最多 N-1 筆轉帳。
     *
     * 「簡化前筆數」以原始欠款邊（每筆支出中，付款人 ≠ 分攤者的那些欠款關係，
     * 合併同一對 (債務人→債權人) 後）為基準，用來對比簡化效果。
     *
     * @return array{
     *   transfers: list<array{from:string,to:string,amount:int}>,
     *   before: int, after: int, conserved: bool
     * }
     */
    public function settle(): array
    {
        $bal = $this->balances();
        $net = $bal['net'];

        // ---- 簡化後：最小現金流 ----
        // 用整數分；複製成 (人,額) 工作集
        $work = [];
        foreach ($net as $person => $v) {
            if ($v !== 0) {
                $work[$person] = $v;
            }
        }
        $transfers = [];
        // 防呆迴圈上限
        $guard = 0;
        while (true) {
            // 找最大債主與最大欠款人
            $maxCreditor = null; $maxC = 0;
            $maxDebtor = null;   $maxD = 0;
            foreach ($work as $p => $v) {
                if ($v > $maxC) { $maxC = $v; $maxCreditor = $p; }
                if ($v < $maxD) { $maxD = $v; $maxDebtor = $p; }
            }
            if ($maxCreditor === null || $maxDebtor === null) {
                break; // 全部結清
            }
            $amount = min($maxC, -$maxD);
            $transfers[] = [
                'from'   => $maxDebtor,   // 欠款人付錢
                'to'     => $maxCreditor, // 給債主
                'amount' => $amount,
            ];
            $work[$maxCreditor] -= $amount;
            $work[$maxDebtor]   += $amount;
            if ($work[$maxCreditor] === 0) { unset($work[$maxCreditor]); }
            if ($work[$maxDebtor] === 0)   { unset($work[$maxDebtor]); }
            if (++$guard > 10000) { break; } // 理論上 N-1 輪內結束
        }

        return [
            'transfers' => $transfers,
            'before'    => $this->rawDebtEdgeCount(),
            'after'     => count($transfers),
            'conserved' => $bal['conserved'],
        ];
    }

    /**
     * 簡化前的「原始欠款邊」筆數：
     * 把每筆支出拆成 (分攤者 → 付款人, 金額) 的欠款邊，
     * 合併同一對 (debtor→creditor) 後計算不重複的有向邊數。
     */
    private function rawDebtEdgeCount(): int
    {
        $db = $this->read();
        $edges = [];
        foreach ($db['expenses'] as $e) {
            $payer = $e['payer'];
            foreach ($e['shares'] as $person => $share) {
                if ($person === $payer || $share <= 0) {
                    continue;
                }
                $k = $person . '->' . $payer;
                $edges[$k] = ($edges[$k] ?? 0) + $share;
            }
        }
        return count($edges);
    }

    // ============ 狀態輸出 ============

    public function state(): array
    {
        $db = $this->read();
        $bal = $this->balances();
        $settle = $this->settle();
        return [
            'members'  => $db['members'],
            'expenses' => $db['expenses'],
            'balances' => $bal,
            'settle'   => $settle,
        ];
    }

    public function reset(): void
    {
        $this->writeRaw([
            'members'   => [],
            'expenses'  => [],
            'seen_keys' => [],
        ]);
    }

    // ============ 持久化（flock 保證原子性） ============

    private function read(): array
    {
        $fp = fopen($this->file, 'r');
        flock($fp, LOCK_SH);
        $json = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $db = json_decode($json, true);
        return is_array($db) ? $db : ['members' => [], 'expenses' => [], 'seen_keys' => []];
    }

    /** 讀-改-寫 包在獨佔鎖內，避免並發支出互相覆蓋 */
    private function mutate(callable $fn): void
    {
        $fp = fopen($this->file, 'c+');
        flock($fp, LOCK_EX);
        $json = (string) stream_get_contents($fp);
        $db = json_decode($json, true);
        if (!is_array($db)) {
            $db = ['members' => [], 'expenses' => [], 'seen_keys' => []];
        }
        $db = $fn($db);
        $out = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $out);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function writeRaw(array $db): void
    {
        $out = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->file, $out, LOCK_EX);
    }
}
