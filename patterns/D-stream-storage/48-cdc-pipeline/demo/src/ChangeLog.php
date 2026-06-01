<?php
declare(strict_types=1);

/**
 * 變更日誌（模擬 binlog → Kafka topic）
 * --------------------------------------------------
 * 每一筆變更事件長這樣（Debezium 風格的精簡版）：
 *   {
 *     lsn:    遞增序號（log sequence number / offset，分區內單調遞增）
 *     op:     'c' | 'u' | 'd'      （create / update / delete）
 *     table:  來源表名
 *     pk:     主鍵值（這裡是 id）
 *     before: 變更前的列（u/d 有；c 為 null）
 *     after:  變更後的列（c/u 有；d 為 null）
 *     ts:     事件時間
 *   }
 *
 * 保證：append-only + 分區內依 LSN 嚴格有序。
 * LSN 用一個原子遞增計數器（檔案鎖）模擬 binlog 的單調位移。
 */
final class ChangeLog
{
    private string $logFile;
    private string $lsnFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->logFile = $dataDir . '/change_log.json';
        $this->lsnFile = $dataDir . '/lsn.txt';
        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, '[]');
        }
        if (!file_exists($this->lsnFile)) {
            file_put_contents($this->lsnFile, '0');
        }
    }

    /** 原子遞增 LSN（模擬 binlog 位移單調遞增） */
    private function nextLsn(): int
    {
        $fp = fopen($this->lsnFile, 'c+');
        flock($fp, LOCK_EX);
        $lsn = (int) trim((string) fread($fp, 64));
        $lsn++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $lsn);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $lsn;
    }

    /** Append 一筆變更事件，回傳完整事件（含分配到的 LSN） */
    public function append(string $op, string $table, int $pk, ?array $before, ?array $after): array
    {
        $event = [
            'lsn' => $this->nextLsn(),
            'op' => $op,
            'table' => $table,
            'pk' => $pk,
            'before' => $before,
            'after' => $after,
            'ts' => gmdate('c'),
        ];
        $fp = fopen($this->logFile, 'c+');
        flock($fp, LOCK_EX);
        $json = stream_get_contents($fp);
        $log = json_decode((string) $json, true) ?: [];
        $log[] = $event;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $event;
    }

    /** 讀全部變更事件（依 LSN 有序） */
    public function all(): array
    {
        $json = (string) file_get_contents($this->logFile);
        $log = json_decode($json, true) ?: [];
        usort($log, static fn($a, $b) => $a['lsn'] <=> $b['lsn']);
        return $log;
    }

    /** 讀 LSN > $afterLsn 的事件（消費者依位移增量拉取） */
    public function since(int $afterLsn): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn($e) => $e['lsn'] > $afterLsn
        ));
    }

    /** 目前最大 LSN（沒有事件回 0） */
    public function headLsn(): int
    {
        $all = $this->all();
        if ($all === []) {
            return 0;
        }
        return (int) end($all)['lsn'];
    }
}
