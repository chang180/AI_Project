<?php
declare(strict_types=1);

/**
 * 儲存層（模擬任務 DB + 執行紀錄 DB + 通知收件匣）
 * --------------------------------------------------
 * 真實系統：
 *   - tasks：可變的任務定義與排程狀態（關聯式 DB，next_run_at 建索引供 scheduler 掃描）。
 *   - runs ：每次觸發的執行紀錄（狀態機），高頻寫，可走獨立表 / 時序庫。
 *   - notifications：投遞紀錄。
 *   冪等鍵需建唯一索引，靠 DB 唯一約束擋住重複入列。
 *
 * 這裡用三個 JSON 檔當「DB」，檔案鎖（flock）模擬原子性，聚焦排程 / 佇列 / 狀態機邏輯。
 */
final class Store
{
    private string $tasksFile;
    private string $runsFile;
    private string $notifyFile;
    private string $counterFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->tasksFile = $dataDir . '/tasks.json';
        $this->runsFile = $dataDir . '/runs.json';
        $this->notifyFile = $dataDir . '/notifications.json';
        $this->counterFile = $dataDir . '/counter.txt';

        foreach ([$this->tasksFile, $this->runsFile, $this->notifyFile] as $f) {
            if (!file_exists($f)) {
                file_put_contents($f, '[]');
            }
        }
        if (!file_exists($this->counterFile)) {
            file_put_contents($this->counterFile, '0');
        }
    }

    // ========== 任務（tasks） ==========

    /** @return array<int,array> */
    public function allTasks(): array
    {
        return $this->read($this->tasksFile);
    }

    public function getTask(string $id): ?array
    {
        foreach ($this->read($this->tasksFile) as $t) {
            if ($t['id'] === $id) {
                return $t;
            }
        }
        return null;
    }

    public function putTask(array $task): void
    {
        $tasks = $this->read($this->tasksFile);
        $tasks[] = $task;
        $this->write($this->tasksFile, $tasks);
    }

    /** 部分更新任務欄位 */
    public function updateTask(string $id, array $patch): void
    {
        $tasks = $this->read($this->tasksFile);
        foreach ($tasks as &$t) {
            if ($t['id'] === $id) {
                $t = array_merge($t, $patch);
                break;
            }
        }
        unset($t);
        $this->write($this->tasksFile, $tasks);
    }

    // ========== 執行紀錄（runs，狀態機） ==========

    /** @return array<int,array> */
    public function allRuns(): array
    {
        return $this->read($this->runsFile);
    }

    public function putRun(array $run): void
    {
        $runs = $this->read($this->runsFile);
        $runs[] = $run;
        $this->write($this->runsFile, $runs);
    }

    public function updateRun(int $runId, array $patch): void
    {
        $runs = $this->read($this->runsFile);
        foreach ($runs as &$r) {
            if ((int) $r['run_id'] === $runId) {
                $r = array_merge($r, $patch);
                break;
            }
        }
        unset($r);
        $this->write($this->runsFile, $runs);
    }

    /** 冪等查詢：依冪等鍵找 run（防止同一觸發重複入列的核心） */
    public function findRunByKey(string $key): ?array
    {
        foreach ($this->read($this->runsFile) as $r) {
            if (($r['idempotency_key'] ?? null) === $key) {
                return $r;
            }
        }
        return null;
    }

    // ========== 通知收件匣 ==========

    /** @return array<int,array> */
    public function allNotifications(): array
    {
        return $this->read($this->notifyFile);
    }

    public function appendNotification(array $n): void
    {
        $list = $this->read($this->notifyFile);
        $list[] = $n;
        $this->write($this->notifyFile, $list);
    }

    // ========== 計數器（任務 id / run id 發號） ==========

    /** 原子遞增全域計數器（檔案鎖模擬 DB sequence） */
    public function nextSeq(): int
    {
        $fp = fopen($this->counterFile, 'c+');
        flock($fp, LOCK_EX);
        $id = (int) trim((string) fread($fp, 64));
        $id++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $id);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $id;
    }

    public function nextTaskId(): string
    {
        return 'task_' . $this->nextSeq();
    }

    public function nextRunId(): int
    {
        return $this->nextSeq();
    }

    /** 清空所有資料（測試頁的「重置」用） */
    public function reset(): void
    {
        file_put_contents($this->tasksFile, '[]');
        file_put_contents($this->runsFile, '[]');
        file_put_contents($this->notifyFile, '[]');
        file_put_contents($this->counterFile, '0');
    }

    // ========== 私有 I/O ==========

    private function read(string $file): array
    {
        $json = (string) file_get_contents($file);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function write(string $file, array $data): void
    {
        file_put_contents(
            $file,
            (string) json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            LOCK_EX
        );
    }
}
