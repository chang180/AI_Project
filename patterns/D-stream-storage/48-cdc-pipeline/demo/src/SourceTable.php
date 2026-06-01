<?php
declare(strict_types=1);

/**
 * 來源資料表（模擬主庫的一張表）
 * --------------------------------------------------
 * 真實系統：這是 MySQL/Postgres 裡一張會被應用程式 insert/update/delete 的表。
 * 每次寫入，資料庫會把變更寫進它自己的 WAL/binlog；Debezium 讀那份 binlog。
 *
 * 這裡用 JSON 檔當「表內容」。重點不在儲存本身，而在「每次寫入時，
 * 我們同步往變更日誌 append 一筆變更事件」——這就是 CDC 的本質。
 * 主鍵：id（int）。
 */
final class SourceTable
{
    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/source_table.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, '{}');
        }
    }

    /** 讀整張表（id => row） */
    public function all(): array
    {
        $json = (string) file_get_contents($this->file);
        return json_decode($json, true) ?: [];
    }

    /** 讀單列；不存在回 null */
    public function get(int $id): ?array
    {
        $rows = $this->all();
        return $rows[(string) $id] ?? null;
    }

    /**
     * 套用一筆變更並回傳 before/after，供呼叫端產生變更事件。
     * $op：'c'（建立/插入）、'u'（更新）、'd'（刪除）。
     * 回傳：['before' => ?array, 'after' => ?array]
     */
    public function apply(string $op, int $id, array $fields): array
    {
        $rows = $this->all();
        $key = (string) $id;
        $before = $rows[$key] ?? null;

        if ($op === 'd') {
            unset($rows[$key]);
            $after = null;
        } else {
            // c 或 u：合併欄位，主鍵固定為 id
            $row = $before ?? ['id' => $id];
            foreach ($fields as $k => $v) {
                if ($k === 'id') {
                    continue; // 主鍵不可改
                }
                $row[$k] = $v;
            }
            $row['id'] = $id;
            $rows[$key] = $row;
            $after = $row;
        }

        $this->write($rows);
        return ['before' => $before, 'after' => $after];
    }

    private function write(array $rows): void
    {
        $fp = fopen($this->file, 'c+');
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
