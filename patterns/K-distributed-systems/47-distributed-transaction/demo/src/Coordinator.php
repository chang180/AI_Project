<?php
declare(strict_types=1);

require_once __DIR__ . '/Participant.php';

/**
 * Coordinator（協調者 / TM, Transaction Manager）
 * --------------------------------------------------
 * 實作兩階段提交（2PC, Two-Phase Commit）：
 *
 *   第一階段 prepare（投票）：
 *     協調者問『所有』參與者「能不能提交?」。每個參與者各自鎖定/預寫並投票。
 *     只要『全部』回 YES → 進入第二階段 commit；
 *     『任一』回 NO 或逾時 → 進入第二階段 abort，全部回滾。
 *
 *   第二階段 commit / abort（決議）：
 *     全 YES → 對所有參與者下 commit，把預寫的變更真正套用。
 *     有 NO  → 對所有（已 prepare 的）參與者下 abort，丟棄預寫、回滾。
 *
 * 範例情境：跨兩個分片轉帳。A 分片扣款、B 分片入帳，要嘛都成功、要嘛都回滾，
 * 藉此展示分散式交易的『原子性』。
 *
 * 協調者本身也有一份決議日誌（decision log），記下每筆交易最終是 COMMIT 還是 ABORT，
 * 這在真實 2PC 裡是關鍵：協調者必須先把決議落盤，才能在崩潰後告訴大家最終結果
 * （否則參與者會卡在 prepared 狀態無限期阻塞 —— 即 2PC 的單點阻塞問題）。
 */
final class Coordinator
{
    /** @var array<string,Participant> 分片名 => 參與者 */
    private array $participants = [];
    private string $logFile;

    public function __construct(private string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->logFile = $dataDir . '/coordinator_log.json';
        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, json_encode(['decisions' => []]));
        }
    }

    public function addParticipant(Participant $p): void
    {
        $this->participants[$p->id] = $p;
    }

    public function participant(string $id): Participant
    {
        return $this->participants[$id];
    }

    /** @return array<string,Participant> */
    public function participants(): array
    {
        return $this->participants;
    }

    /**
     * 跨分片轉帳：from 分片扣款、to 分片入帳，走完整 2PC。
     * @return array 詳細的兩階段過程（給 UI 呈現）
     */
    public function transfer(string $fromShard, string $fromAcc, string $toShard, string $toAcc, int $amount): array
    {
        $txId = 'tx_' . substr(bin2hex(random_bytes(4)), 0, 8);

        // 這筆交易牽涉哪些參與者、各自要套用什麼變更
        $plan = [
            $fromShard => [['account' => $fromAcc, 'delta' => -$amount]],
            $toShard   => [['account' => $toAcc,   'delta' => +$amount]],
        ];

        $trace = [
            'txId'   => $txId,
            'amount' => $amount,
            'from'   => "$fromShard:$fromAcc",
            'to'     => "$toShard:$toAcc",
            'phase1' => [],
            'phase2' => [],
        ];

        // ---------- 第一階段：prepare / 投票 ----------
        $allYes = true;
        $voted = [];   // 已投票（不論 yes/no）的參與者，abort 時要通知它們解鎖
        foreach ($plan as $shard => $ops) {
            $p = $this->participants[$shard];
            $voted[] = $shard;
            try {
                $yes = $p->prepare($txId, $ops);
            } catch (\Throwable $e) {
                $yes = false;   // 逾時/拋錯 → 視為投 NO
            }
            $trace['phase1'][] = ['shard' => $shard, 'vote' => $yes ? 'YES' : 'NO'];
            if (!$yes) {
                $allYes = false;
                break;          // 已有人投 NO，不必再問其餘人
            }
        }

        // ---------- 第二階段：依投票結果決議 ----------
        if ($allYes) {
            $this->writeDecision($txId, 'COMMIT');           // 決議先落盤
            $trace['decision'] = 'COMMIT';
            foreach (array_keys($plan) as $shard) {
                try {
                    $this->participants[$shard]->commit($txId);
                    $trace['phase2'][] = ['shard' => $shard, 'action' => 'commit', 'ok' => true];
                } catch (\Throwable $e) {
                    // commit 階段參與者失敗：決議已是 COMMIT，協調者會持續重試直到成功
                    // （2PC：一旦決議 commit，就不能反悔；參與者恢復後依 WAL 重放）
                    $trace['phase2'][] = ['shard' => $shard, 'action' => 'commit', 'ok' => false, 'note' => '失敗→將重試重放'];
                }
            }
        } else {
            $this->writeDecision($txId, 'ABORT');
            $trace['decision'] = 'ABORT';
            foreach ($voted as $shard) {
                $this->participants[$shard]->abort($txId);   // 通知已 prepare 的人丟棄預寫、回滾
                $trace['phase2'][] = ['shard' => $shard, 'action' => 'abort', 'ok' => true];
            }
        }

        return $trace;
    }

    /** 全系統帳戶總額（用來驗證原子性：commit 或 abort 後總額都應守恆） */
    public function totalBalance(): int
    {
        $total = 0;
        foreach ($this->participants as $p) {
            foreach ($p->accounts() as $bal) {
                $total += (int) $bal;
            }
        }
        return $total;
    }

    public function decisions(): array
    {
        $d = json_decode((string) file_get_contents($this->logFile), true) ?: ['decisions' => []];
        return $d['decisions'];
    }

    private function writeDecision(string $txId, string $decision): void
    {
        $fp = fopen($this->logFile, 'c+');
        flock($fp, LOCK_EX);
        $json = (string) stream_get_contents($fp);
        $d = json_decode($json, true) ?: ['decisions' => []];
        $d['decisions'][] = ['txId' => $txId, 'decision' => $decision, 'at' => gmdate('c')];
        if (count($d['decisions']) > 100) {
            $d['decisions'] = array_slice($d['decisions'], -100);
        }
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
