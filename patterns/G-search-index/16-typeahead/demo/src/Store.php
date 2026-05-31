<?php
declare(strict_types=1);

/**
 * 詞頻儲存（模擬離線聚合出的 term_frequency 表 + 查詢日誌）
 * --------------------------------------------------------------
 * 真實系統：query_log 進 Kafka，離線批次聚合出 term_frequency，
 * 再用它重建駐記憶體 Trie。PHP 每個請求都是全新進程、Trie 不能常駐記憶體，
 * 因此這裡用 JSON 檔保存「詞 => 頻率」，每個請求載入後重建 Trie；
 * 「被選中」事件則就地把頻率寫回 JSON（示意 query_log 聚合的結果）。
 */
final class Store
{
    private string $file;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/terms.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode($this->seed(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    /** 初始種子詞（含頻率）——中英混合，示範字元級前綴。 */
    private function seed(): array
    {
        return [
            '自動完成'   => 120,
            '自助餐'     => 90,
            '自行車'     => 75,
            '自由女神'   => 40,
            '自然語言'   => 60,
            '系統設計'   => 200,
            '系統管理員' => 55,
            '系統還原'   => 30,
            'search'     => 300,
            'search engine' => 180,
            'searchlight'   => 20,
            'seattle'    => 95,
            'season'     => 70,
        ];
    }

    /** @return array<string,int> 詞 => 頻率 */
    public function all(): array
    {
        $json = (string) file_get_contents($this->file);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /** 把某詞頻率設為指定值（select 後寫回，使下次請求重建時生效）。 */
    public function setFreq(string $term, int $freq): void
    {
        $data = $this->all();
        if (!array_key_exists($term, $data)) {
            return;
        }
        $data[$term] = $freq;
        $this->write($data);
    }

    /** 重設為種子（測試頁的「重設」用）。 */
    public function reset(): void
    {
        $this->write($this->seed());
    }

    private function write(array $data): void
    {
        file_put_contents(
            $this->file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
