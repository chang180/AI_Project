<?php
declare(strict_types=1);

/**
 * 來源伺服器（Origin Server）
 * --------------------------------------------------
 * CDN 的「真實資料源」。邊緣節點未命中（miss）時，會回源（origin fetch）到這裡抓資產，
 * 並把回應連同 Cache-Control 的 TTL 一起帶回去存進邊緣快取。
 *
 * 本 demo 用 JSON 檔（origin.json）當 origin 的資產倉庫，模擬真實的物件儲存 / 應用伺服器。
 * 每個資產帶一個 cache_control_ttl（秒），代表 origin 回應 Cache-Control: max-age=<ttl>。
 *
 * fetchCount 統計「實際回源次數」——這是 CDN 最在意的指標之一（回源比越低越省頻寬/越快）。
 */
final class Origin
{
    private string $file;
    private string $counterFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/origin.json';
        $this->counterFile = $dataDir . '/origin_fetches.txt';
        if (!file_exists($this->file)) {
            $this->seed();
        }
        if (!file_exists($this->counterFile)) {
            file_put_contents($this->counterFile, '0');
        }
    }

    /**
     * 回源抓取資產。回傳 [內容, ttl秒] 或 null（origin 也沒有）。
     * 每次呼叫代表一次真實的回源往返（昂貴、慢），因此計數。
     */
    public function fetch(string $asset): ?array
    {
        $db = $this->read();
        if (!isset($db[$asset])) {
            return null;
        }
        $this->bumpFetchCount(); // 跨請求持久化回源次數（每個 HTTP 請求是獨立 PHP 程序）
        return [
            'body' => $db[$asset]['body'],
            'ttl'  => (int) $db[$asset]['cache_control_ttl'],
        ];
    }

    /** 在 origin 放/更新一個資產（含其 Cache-Control TTL） */
    public function put(string $asset, string $body, int $ttl): void
    {
        $db = $this->read();
        $db[$asset] = ['body' => $body, 'cache_control_ttl' => $ttl];
        $this->write($db);
    }

    public function list(): array
    {
        return array_keys($this->read());
    }

    public function fetchCount(): int
    {
        return (int) trim((string) file_get_contents($this->counterFile));
    }

    /** 原子遞增回源次數（檔案鎖） */
    private function bumpFetchCount(): void
    {
        $fp = fopen($this->counterFile, 'c+');
        flock($fp, LOCK_EX);
        $n = (int) trim((string) fread($fp, 64)) + 1;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $n);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /** 預先放幾個示範資產（不同 TTL：圖片長、API/HTML 短） */
    private function seed(): void
    {
        $this->write([
            '/logo.png'        => ['body' => '«PNG bytes of logo»',          'cache_control_ttl' => 86400],
            '/app.js'          => ['body' => 'console.log("app v1");',        'cache_control_ttl' => 3600],
            '/style.css'       => ['body' => 'body{margin:0}',                'cache_control_ttl' => 3600],
            '/index.html'      => ['body' => '<h1>Home</h1>',                 'cache_control_ttl' => 30],
            '/api/config.json' => ['body' => '{"feature":"on"}',              'cache_control_ttl' => 10],
        ]);
    }

    private function read(): array
    {
        $json = (string) file_get_contents($this->file);
        return json_decode($json, true) ?: [];
    }

    private function write(array $db): void
    {
        file_put_contents(
            $this->file,
            json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
