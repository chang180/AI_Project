<?php
declare(strict_types=1);

/**
 * Participant（參與者 / 分片 RM, Resource Manager）
 * --------------------------------------------------
 * 在 2PC 裡，每個分片是一個「參與者」。它持有一部分帳戶餘額，
 * 並實作兩階段提交的三個動作：
 *
 *   prepare(txId, ops)：第一階段。檢查「能不能提交?」——
 *       驗證餘額足夠、把這筆變更「預寫」進 WAL 並『鎖定』被動到的帳戶，
 *       但『尚未』套用到正式餘額。回 true(yes/vote-commit) 或 false(no/vote-abort)。
 *   commit(txId)：第二階段。把 prepare 預寫的變更真正套用到餘額、解鎖、清 WAL。
 *   abort(txId)：第二階段。丟棄 prepare 的預寫、解鎖，餘額不變（回滾）。
 *
 * 為了單機可重現，每個參與者的狀態存成一個 JSON 檔（data/shard_<id>.json），
 * 用 flock 原子化讀寫。WAL（prepared 記錄）也存在同一檔的 "prepared" 區段，
 * 模擬「預寫日誌已落盤、即使協調者稍後才下令也能據此 commit/abort」。
 *
 * 失敗注入：setFailMode('prepare'|'commit') 讓此參與者在該階段拋錯，
 * 用來示範「某參與者在 prepare 後失敗 → 協調者決定 abort → 大家回滾，錢不會少」。
 */
final class Participant
{
    private string $file;

    public function __construct(
        public readonly string $id,
        string $dataDir
    ) {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/shard_' . $id . '.json';
        if (!file_exists($this->file)) {
            $this->save([
                'accounts' => [],   // 帳號 => 餘額
                'prepared' => [],   // txId => { ops, locked: [帳號...] }  （WAL）
                'failMode' => '',   // ''|'prepare'|'commit'  注入失敗用
                'log'      => [],   // 人類可讀的事件流（給 UI 看）
            ]);
        }
    }

    // ---------------------------------------------------------------
    // 帳戶 / 設定
    // ---------------------------------------------------------------

    /** 設定某帳戶初始餘額 */
    public function setBalance(string $account, int $amount): void
    {
        $this->mutate(function (array $s) use ($account, $amount): array {
            $s['accounts'][$account] = $amount;
            return $s;
        });
    }

    public function balance(string $account): int
    {
        $s = $this->load();
        return (int) ($s['accounts'][$account] ?? 0);
    }

    /** 此分片所有帳戶餘額 */
    public function accounts(): array
    {
        return $this->load()['accounts'];
    }

    /** 注入失敗：在指定階段拋錯（''=不失敗） */
    public function setFailMode(string $mode): void
    {
        $this->mutate(function (array $s) use ($mode): array {
            $s['failMode'] = $mode;
            return $s;
        });
    }

    public function failMode(): string
    {
        return (string) $this->load()['failMode'];
    }

    public function logLines(): array
    {
        return $this->load()['log'];
    }

    /** 是否有帳戶被某交易鎖定中（prepare 後、未 commit/abort 前） */
    public function isLocked(string $account): bool
    {
        $s = $this->load();
        foreach ($s['prepared'] as $p) {
            if (in_array($account, $p['locked'], true)) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------
    // 2PC 三動作
    // ---------------------------------------------------------------

    /**
     * 第一階段 prepare：能不能提交?
     * $ops：[ ['account'=>'A', 'delta'=>-100], ... ] 這個分片要套用的變更
     * 回 true=vote-commit（已預寫+鎖定）／false=vote-abort（餘額不足等）
     */
    public function prepare(string $txId, array $ops): bool
    {
        $vote = false;
        $this->mutate(function (array $s) use ($txId, $ops, &$vote): array {
            // 注入：prepare 階段直接失敗（模擬參與者當機/逾時）
            if ($s['failMode'] === 'prepare') {
                $s['log'][] = "[$txId] prepare → 失敗(注入)：投 NO";
                $vote = false;
                return $s;
            }

            // 1) 檢查可行性：模擬「餘額不可為負」的約束
            foreach ($ops as $op) {
                $acc = $op['account'];
                $cur = (int) ($s['accounts'][$acc] ?? 0);
                if ($cur + (int) $op['delta'] < 0) {
                    $s['log'][] = "[$txId] prepare → 餘額不足($acc)：投 NO";
                    $vote = false;
                    return $s;     // vote-abort，不預寫、不鎖定
                }
            }
            // 2) 鎖定不能被其他交易同時動到的帳戶（簡化：嚴格鎖）
            $locked = array_values(array_unique(array_map(
                static fn(array $op): string => $op['account'],
                $ops
            )));
            foreach ($locked as $acc) {
                foreach ($s['prepared'] as $otherTx => $p) {
                    if ($otherTx !== $txId && in_array($acc, $p['locked'], true)) {
                        $s['log'][] = "[$txId] prepare → $acc 已被 $otherTx 鎖定：投 NO";
                        $vote = false;
                        return $s;
                    }
                }
            }
            // 3) 預寫（WAL）：記下這筆變更，但『還沒』動正式餘額
            $s['prepared'][$txId] = ['ops' => $ops, 'locked' => $locked];
            $s['log'][] = "[$txId] prepare → 預寫+鎖定 " . implode(',', $locked) . "：投 YES";
            $vote = true;
            return $s;
        });
        return $vote;
    }

    /** 第二階段 commit：真正套用預寫的變更 */
    public function commit(string $txId): void
    {
        $fail = false;
        $this->mutate(function (array $s) use ($txId, &$fail): array {
            if (!isset($s['prepared'][$txId])) {
                // 沒 prepare 過就 commit：忽略（冪等）
                return $s;
            }
            // 注入：commit 階段失敗 → 變更仍留在 WAL，重啟後可重放（這裡僅標記）
            if ($s['failMode'] === 'commit') {
                $s['log'][] = "[$txId] commit → 失敗(注入)：WAL 保留，待恢復重放";
                $fail = true;
                return $s;
            }
            foreach ($s['prepared'][$txId]['ops'] as $op) {
                $acc = $op['account'];
                $s['accounts'][$acc] = (int) ($s['accounts'][$acc] ?? 0) + (int) $op['delta'];
            }
            unset($s['prepared'][$txId]);   // 解鎖 + 清 WAL
            $s['log'][] = "[$txId] commit → 套用變更、解鎖";
            return $s;
        });
        if ($fail) {
            throw new RuntimeException("participant {$this->id} commit failed (injected)");
        }
    }

    /** 第二階段 abort：丟棄預寫、回滾（餘額不變） */
    public function abort(string $txId): void
    {
        $this->mutate(function (array $s) use ($txId): array {
            if (isset($s['prepared'][$txId])) {
                unset($s['prepared'][$txId]);   // 丟棄 WAL + 解鎖；正式餘額從未被動過
                $s['log'][] = "[$txId] abort → 丟棄預寫、解鎖（回滾）";
            }
            return $s;
        });
    }

    // ---------------------------------------------------------------
    // 檔案 I/O（flock 原子化）
    // ---------------------------------------------------------------

    private function load(): array
    {
        $fp = fopen($this->file, 'r');
        flock($fp, LOCK_SH);
        $json = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_decode($json, true) ?: [
            'accounts' => [], 'prepared' => [], 'failMode' => '', 'log' => [],
        ];
    }

    private function save(array $s): void
    {
        file_put_contents(
            $this->file,
            json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /** 讀-改-寫的原子化包裝（整個過程持有獨佔鎖） */
    private function mutate(callable $fn): void
    {
        $fp = fopen($this->file, 'c+');
        flock($fp, LOCK_EX);
        $json = (string) stream_get_contents($fp);
        $s = json_decode($json, true) ?: [
            'accounts' => [], 'prepared' => [], 'failMode' => '', 'log' => [],
        ];
        $s = $fn($s);
        // 限制 log 長度，避免無限成長
        if (count($s['log']) > 200) {
            $s['log'] = array_slice($s['log'], -200);
        }
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
