<?php
declare(strict_types=1);

/**
 * 雙分錄帳本（append-only double-entry ledger）
 * --------------------------------------------------
 * 真實系統：帳本分錄存於強一致主庫，append-only、永不就地改/刪；
 * 餘額由分錄彙總得到，並物化一份快取以加速讀取。
 *
 * 這裡用 JSON 檔當「DB」：
 *   - accounts.json   帳戶與物化餘額（單位：整數分 cents，避免浮點誤差）
 *   - ledger.json     分錄陣列（append-only，真相來源）
 *
 * 核心不變式：
 *   1) 每筆 transfer 產生 debit + credit 兩筆分錄，金額相等、方向相反 → 淨額為 0。
 *   2) 全帳隨時 Σ(借) = Σ(貸)；任一帳戶餘額 = Σ(貸入) - Σ(借出)。
 *   3) 物化餘額必須等於由帳本重算的餘額（對帳）。
 */
final class Ledger
{
    private string $accountsFile;
    private string $ledgerFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->accountsFile = $dataDir . '/accounts.json';
        $this->ledgerFile   = $dataDir . '/ledger.json';
        if (!file_exists($this->accountsFile)) {
            file_put_contents($this->accountsFile, '{}');
        }
        if (!file_exists($this->ledgerFile)) {
            file_put_contents($this->ledgerFile, '[]');
        }
        // 系統金庫帳戶（流通在外的錢從這裡發行；其餘額可為負）。
        // 自動建立，確保物化餘額與帳本重算一致（對帳無差異）。
        $accounts = $this->readAccounts();
        if (!isset($accounts['__vault__'])) {
            $accounts['__vault__'] = ['account_id' => '__vault__', 'balance' => 0, 'currency' => 'TWD'];
            $this->writeAccounts($accounts);
        }
    }

    /** 開帳戶並給定初始餘額（分）。初始入帳也以分錄記錄（系統金庫 → 帳戶）。 */
    public function openAccount(string $id, int $initialCents = 0): void
    {
        if ($initialCents < 0) {
            throw new InvalidArgumentException('初始餘額不可為負');
        }
        $accounts = $this->readAccounts();
        if (isset($accounts[$id])) {
            throw new RuntimeException("帳戶已存在：$id");
        }
        $accounts[$id] = ['account_id' => $id, 'balance' => 0, 'currency' => 'TWD'];
        $this->writeAccounts($accounts);

        if ($initialCents > 0) {
            // 初始入帳：借「系統金庫」、貸「使用者帳戶」（雙分錄、總和為零）
            $this->appendDoubleEntry(
                transferId: 'open_' . $id,
                fromAccount: '__vault__',   // 系統金庫（可為負，代表流通在外的錢）
                toAccount: $id,
                amountCents: $initialCents,
                memo: '開戶初始入帳'
            );
            $this->applyBalanceDelta($id, $initialCents);
            $this->applyBalanceDelta('__vault__', -$initialCents, allowNegative: true);
        }
    }

    public function exists(string $id): bool
    {
        return isset($this->readAccounts()[$id]);
    }

    /** 讀餘額（物化值；單位：分） */
    public function balance(string $id): int
    {
        $accounts = $this->readAccounts();
        return (int) ($accounts[$id]['balance'] ?? 0);
    }

    /**
     * 追加一筆雙分錄（debit + credit），append-only。
     * 不在此處更新餘額——餘額更新由呼叫端在同一交易內完成（見 TransferService）。
     * @return string 此筆 transfer 寫入的分錄識別
     */
    public function appendDoubleEntry(
        string $transferId,
        string $fromAccount,
        string $toAccount,
        int $amountCents,
        string $memo = ''
    ): void {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('金額必須為正整數（分）');
        }
        $ledger = $this->readLedger();
        $now = gmdate('c');
        // 借（debit）：出錢方
        $ledger[] = [
            'entry_id'    => count($ledger) + 1,
            'transfer_id' => $transferId,
            'account_id'  => $fromAccount,
            'direction'   => 'D',
            'amount'      => $amountCents,
            'memo'        => $memo,
            'created_at'  => $now,
        ];
        // 貸（credit）：收錢方
        $ledger[] = [
            'entry_id'    => count($ledger) + 1,
            'transfer_id' => $transferId,
            'account_id'  => $toAccount,
            'direction'   => 'C',
            'amount'      => $amountCents,
            'memo'        => $memo,
            'created_at'  => $now,
        ];
        $this->writeLedger($ledger);
    }

    /**
     * 條件式更新物化餘額（防透支的關鍵）。
     * 模擬 SQL：UPDATE accounts SET balance = balance + :delta WHERE balance + :delta >= 0
     * @return bool 是否成功（false = 會造成透支，已拒絕）
     */
    public function applyBalanceDelta(string $id, int $deltaCents, bool $allowNegative = false): bool
    {
        $accounts = $this->readAccounts();
        if (!isset($accounts[$id])) {
            throw new RuntimeException("帳戶不存在：$id");
        }
        $newBalance = (int) $accounts[$id]['balance'] + $deltaCents;
        if (!$allowNegative && $newBalance < 0) {
            return false; // 防透支：餘額永不為負
        }
        $accounts[$id]['balance'] = $newBalance;
        $this->writeAccounts($accounts);
        return true;
    }

    /** 列出全部分錄（append-only） */
    public function entries(): array
    {
        return $this->readLedger();
    }

    /** 列出某帳戶的分錄（對帳單） */
    public function entriesOf(string $id): array
    {
        return array_values(array_filter(
            $this->readLedger(),
            static fn(array $e): bool => $e['account_id'] === $id
        ));
    }

    public function accounts(): array
    {
        return $this->readAccounts();
    }

    /**
     * 對帳檢查：
     *   - 全帳 Σ借 是否等於 Σ貸（雙分錄守恆）
     *   - 由帳本重算的每個帳戶餘額，是否等於物化餘額
     * @return array{balanced:bool, total_debit:int, total_credit:int, recomputed:array<string,int>, materialized:array<string,int>, mismatches:array<string,array>}
     */
    public function reconcile(): array
    {
        $ledger = $this->readLedger();
        $totalDebit = 0;
        $totalCredit = 0;
        $recomputed = [];
        foreach ($ledger as $e) {
            $acct = $e['account_id'];
            $amt = (int) $e['amount'];
            $recomputed[$acct] ??= 0;
            if ($e['direction'] === 'D') {
                $totalDebit += $amt;
                $recomputed[$acct] -= $amt; // 借 = 出錢 = 餘額減
            } else {
                $totalCredit += $amt;
                $recomputed[$acct] += $amt; // 貸 = 收錢 = 餘額加
            }
        }
        $materialized = [];
        foreach ($this->readAccounts() as $id => $a) {
            $materialized[$id] = (int) $a['balance'];
        }
        // 比對重算餘額 vs 物化餘額
        $mismatches = [];
        $allIds = array_unique(array_merge(array_keys($recomputed), array_keys($materialized)));
        foreach ($allIds as $id) {
            $r = $recomputed[$id] ?? 0;
            $m = $materialized[$id] ?? 0;
            if ($r !== $m) {
                $mismatches[$id] = ['recomputed' => $r, 'materialized' => $m];
            }
        }
        return [
            'balanced'     => $totalDebit === $totalCredit,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'recomputed'   => $recomputed,
            'materialized' => $materialized,
            'mismatches'   => $mismatches,
        ];
    }

    // ============ 內部存取（JSON 檔模擬 DB） ============
    private function readAccounts(): array
    {
        return json_decode((string) file_get_contents($this->accountsFile), true) ?: [];
    }

    private function writeAccounts(array $a): void
    {
        file_put_contents(
            $this->accountsFile,
            json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function readLedger(): array
    {
        return json_decode((string) file_get_contents($this->ledgerFile), true) ?: [];
    }

    private function writeLedger(array $l): void
    {
        file_put_contents(
            $this->ledgerFile,
            json_encode($l, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
