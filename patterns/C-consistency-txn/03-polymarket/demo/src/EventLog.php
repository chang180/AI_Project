<?php
declare(strict_types=1);

/**
 * 事件日誌（Event Sourcing / append-only log）
 * --------------------------------------------------
 * 對應真實元件：交易所的「事件溯源日誌」（如 Kafka topic + 持久化 commit log）。
 *
 * 設計重點：
 *  - 所有改變系統狀態的動作（下單、凍結保證金、成交、過帳、結算）都先寫成一筆
 *    不可變的事件 append 進來，序號（seq）單調遞增。
 *  - 系統的當前狀態 = 從第 0 筆事件依序重放（replay）的結果 → 可重建、可審計、可對帳。
 *  - 撮合引擎只需保證「事件寫入的順序」正確，崩潰後重放即回到一致狀態。
 *
 * 這裡用 JSON 檔當持久化 log，用檔案鎖保證 append 的原子與順序，
 * 以呈現「event sourcing 保證可重放與審計」的核心概念（非生產級）。
 */
final class EventLog
{
    private string $logFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->logFile = $dataDir . '/events.log.json';
        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, '[]');
        }
    }

    /**
     * Append 一筆不可變事件。回傳該事件的序號 seq。
     * 用 LOCK_EX 包住「讀目前 log → 算下一個 seq → 寫回」，保證序列化 append。
     */
    public function append(string $type, array $payload): int
    {
        $fp = fopen($this->logFile, 'c+');
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $events = json_decode($raw ?: '[]', true) ?: [];
        $seq = count($events);
        $events[] = [
            'seq'  => $seq,
            'type' => $type,             // 事件型別：ORDER_PLACED / MARGIN_HELD / TRADE_EXECUTED / ...
            'ts'   => gmdate('c'),
            'data' => $payload,
        ];
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $seq;
    }

    /** 取回完整事件序列（供重放 / 審計 / 對帳）。 */
    public function all(): array
    {
        $raw = (string) file_get_contents($this->logFile);
        return json_decode($raw, true) ?: [];
    }

    /** 最近 N 筆事件（測試頁顯示用）。 */
    public function tail(int $n): array
    {
        $all = $this->all();
        return array_slice($all, max(0, count($all) - $n));
    }

    public function count(): int
    {
        return count($this->all());
    }
}
