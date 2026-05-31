<?php
declare(strict_types=1);

/**
 * 轉帳服務（idempotent transfer with overdraft protection）
 * --------------------------------------------------
 * 真實系統：在「同一個 DB 交易」內，原子寫入 debit/credit 兩筆分錄並更新雙方餘額；
 *           扣款走條件更新防透支；用 idempotency key 去重，重送回放同一結果。
 *
 * 這裡用「檔案鎖」把整段過帳序列化（模擬 DB 交易 / 同帳戶序列化），
 * 並把冪等鍵與結果持久化於 idempotency.json。
 *
 * 三道防線：
 *   1) 冪等：同 key 重送 → 回原 transfer 結果，不重複扣款（exactly-once 副作用）。
 *   2) 防透支：扣款走條件更新，餘額不足直接拒，餘額永不為負。
 *   3) 原子：借/貸分錄 + 雙方餘額更新在鎖內一起完成，全成或全敗。
 */
final class TransferService
{
    private Ledger $ledger;
    private string $idemFile;
    private string $lockFile;
    private string $seqFile;

    public function __construct(Ledger $ledger, string $dataDir)
    {
        $this->ledger   = $ledger;
        $this->idemFile = $dataDir . '/idempotency.json';
        $this->lockFile = $dataDir . '/transfer.lock';
        $this->seqFile  = $dataDir . '/txn_seq.txt';
        if (!file_exists($this->idemFile)) {
            file_put_contents($this->idemFile, '{}');
        }
        if (!file_exists($this->lockFile)) {
            file_put_contents($this->lockFile, '');
        }
        if (!file_exists($this->seqFile)) {
            file_put_contents($this->seqFile, '0');
        }
    }

    /**
     * 轉帳 from → to，金額為正整數分。
     *
     * @param string $idempotencyKey 用戶端產生的唯一鍵；同鍵重送回原結果
     * @return array{status:string, transfer_id?:string, from?:string, to?:string,
     *               amount?:int, entries?:array, error?:string, idempotent_replay?:bool}
     */
    public function transfer(string $from, string $to, int $amountCents, string $idempotencyKey): array
    {
        // ---- 基本驗證（金額須為正整數分；不可同帳互轉；帳戶須存在）----
        if ($idempotencyKey === '') {
            return ['status' => 'rejected', 'error' => '缺少 idempotency key'];
        }
        if ($amountCents <= 0) {
            return ['status' => 'rejected', 'error' => '金額必須為正整數（分）'];
        }
        if ($from === $to) {
            return ['status' => 'rejected', 'error' => '不可轉給自己'];
        }
        if (!$this->ledger->exists($from) || !$this->ledger->exists($to)) {
            return ['status' => 'rejected', 'error' => '帳戶不存在'];
        }

        // ---- 進入臨界區：用檔案鎖序列化整段過帳（模擬 DB 交易 / 同帳戶序列化）----
        $lock = fopen($this->lockFile, 'c');
        flock($lock, LOCK_EX);
        try {
            // ---- 冪等去重：先查鍵庫 ----
            $idem = $this->readIdem();
            $requestHash = hash('sha256', "$from|$to|$amountCents");
            if (isset($idem[$idempotencyKey])) {
                $rec = $idem[$idempotencyKey];
                // 同鍵但請求內容不同 → 視為衝突，避免冒充不同交易
                if (($rec['request_hash'] ?? '') !== $requestHash) {
                    return ['status' => 'conflict', 'error' => '同一 idempotency key 對應不同請求內容'];
                }
                // 已完成：回放原結果，不重複扣款
                $result = $rec['result'];
                $result['idempotent_replay'] = true;
                return $result;
            }

            // ---- 防透支：條件式扣款（餘額不足 → 受影響列數=0 → 拒絕）----
            $ok = $this->ledger->applyBalanceDelta($from, -$amountCents);
            if (!$ok) {
                // 不寫冪等鍵：失敗可重試（餘額補足後可成功）
                return [
                    'status' => 'rejected',
                    'error'  => '餘額不足（防透支）',
                    'from'   => $from,
                    'balance' => $this->ledger->balance($from),
                ];
            }

            // ---- 原子過帳：對手方入帳 + append 雙分錄（與上方扣款同在鎖內，全成或全敗語意）----
            $this->ledger->applyBalanceDelta($to, +$amountCents, allowNegative: true);
            $transferId = $this->nextTransferId();
            $this->ledger->appendDoubleEntry(
                transferId: $transferId,
                fromAccount: $from,
                toAccount: $to,
                amountCents: $amountCents,
                memo: 'transfer'
            );

            $result = [
                'status'      => 'posted',
                'transfer_id' => $transferId,
                'from'        => $from,
                'to'          => $to,
                'amount'      => $amountCents,
                'entries'     => [
                    ['account' => $from, 'direction' => 'D', 'delta' => -$amountCents],
                    ['account' => $to,   'direction' => 'C', 'delta' => +$amountCents],
                ],
            ];

            // ---- 持久化冪等鍵 → 結果（供日後重送回放）----
            $idem[$idempotencyKey] = [
                'request_hash' => $requestHash,
                'status'       => 'done',
                'result'       => $result,
                'created_at'   => gmdate('c'),
            ];
            $this->writeIdem($idem);

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 交易序號（用檔案鎖模擬 DB sequence） */
    private function nextTransferId(): string
    {
        $fp = fopen($this->seqFile, 'c+');
        flock($fp, LOCK_EX);
        $n = (int) trim((string) fread($fp, 64)) + 1;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $n);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return 'txn_' . $n;
    }

    private function readIdem(): array
    {
        return json_decode((string) file_get_contents($this->idemFile), true) ?: [];
    }

    private function writeIdem(array $i): void
    {
        file_put_contents(
            $this->idemFile,
            json_encode($i, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
