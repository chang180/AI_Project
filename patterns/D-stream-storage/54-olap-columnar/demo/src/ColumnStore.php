<?php
declare(strict_types=1);

/**
 * 列式儲存（Columnar Store）核心
 * --------------------------------------------------
 * 重點：同一「欄」的所有值連續存在一起（每欄一個陣列），
 *       而非像 OLTP 那樣「每一筆（列）的所有欄連續」存。
 *
 *   行式（row-store，OLTP）：
 *     [ {ts,region,device,amount}, {ts,region,device,amount}, ... ]
 *     一筆接一筆，要讀某一欄也得把整列搬進來。
 *
 *   列式（column-store，OLAP，本檔）：
 *     ts     = [t0, t1, t2, ...]
 *     region = [r0, r1, r2, ...]
 *     amount = [a0, a1, a2, ...]
 *     每欄獨立連續 → 分析查詢「只掃用到的欄」，其他欄完全不碰。
 *
 * 真實系統：ClickHouse / BigQuery / Snowflake / Parquet 皆是此模型，
 *           並加上欄壓縮、min/max 跳塊、向量化執行、MPP 平行掃描。
 * 這裡用記憶體 + JSON 檔模擬，但「只掃需要欄 / 壓縮 / 向量化聚合」皆為真實計算。
 */
final class ColumnStore
{
    private string $dbFile;

    /** schema：欄名 → 型別（'int' | 'float' | 'string'） */
    private array $schema = [
        'ts'     => 'int',
        'region' => 'string',
        'device' => 'string',
        'amount' => 'float',
    ];

    /** @var array<string,array> 每欄一個連續陣列：columns[欄名] = [值, 值, ...] */
    private array $columns = [];

    private int $rowCount = 0;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dbFile = $dataDir . '/columns.json';
        $this->load();
    }

    public function schema(): array { return $this->schema; }
    public function columnNames(): array { return array_keys($this->schema); }
    public function rowCount(): int { return $this->rowCount; }

    /**
     * 批次載入「列」資料 → 拆成各欄連續陣列（這就是 ETL 入庫時的「行轉列」）。
     * @param array<int,array<string,mixed>> $rows 每筆是 {ts,region,device,amount}
     */
    public function loadRows(array $rows): void
    {
        foreach ($this->columnNames() as $col) {
            if (!isset($this->columns[$col])) {
                $this->columns[$col] = [];
            }
        }
        foreach ($rows as $row) {
            foreach ($this->columnNames() as $col) {
                $v = $row[$col] ?? null;
                $this->columns[$col][] = $this->cast($col, $v);
            }
            $this->rowCount++;
        }
        $this->persist();
    }

    /** 清空（重新載入用） */
    public function reset(): void
    {
        $this->columns = [];
        $this->rowCount = 0;
        $this->persist();
    }

    /** 取得某一欄的連續陣列（分析引擎只會抓它真正用到的欄） */
    public function column(string $name): array
    {
        if (!isset($this->schema[$name])) {
            throw new InvalidArgumentException("未知欄位：$name");
        }
        return $this->columns[$name] ?? [];
    }

    private function cast(string $col, mixed $v): mixed
    {
        return match ($this->schema[$col]) {
            'int'    => (int) $v,
            'float'  => (float) $v,
            default  => (string) $v,
        };
    }

    // ---------- 持久化（JSON 檔模擬磁碟上的列式檔，如 Parquet）----------
    private function load(): void
    {
        if (!file_exists($this->dbFile)) {
            $this->columns = [];
            $this->rowCount = 0;
            return;
        }
        $json = (string) file_get_contents($this->dbFile);
        $data = json_decode($json, true) ?: [];
        $this->columns = $data['columns'] ?? [];
        $this->rowCount = (int) ($data['rowCount'] ?? 0);
    }

    private function persist(): void
    {
        $payload = json_encode(
            ['rowCount' => $this->rowCount, 'columns' => $this->columns],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        file_put_contents($this->dbFile, $payload, LOCK_EX);
    }
}
