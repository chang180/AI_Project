<?php
declare(strict_types=1);

/**
 * 聊天室 fan-out + 觀看人數
 * --------------------------------------------------
 * 對應真實元件：直播聊天服務（依 stream_id 分房）+ 同時在線計數。
 *
 * fan-out 是直播的第二個放大器：一則訊息要推給房內「所有」觀眾，
 *   放大倍數 = 訊息數 × 觀眾數。真實系統用 WebSocket + 階層式廣播（樹狀扇出）。
 * 這裡用「房內每位觀眾各有一個收件匣（inbox）」模擬扇出：
 *   送訊息時把訊息複製進房內每個 inbox（這就是 fan-out 的本質與成本來源）。
 *
 * ViewerCounter：以 join/leave 維護同時在線人數（presence）。
 *   真實系統用心跳 + TTL，掉線自動移除，顯示時走近似計數（HLL）。
 */
final class ChatRoom
{
    private string $stateFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->stateFile = $dataDir . '/chat.json';
        if (!file_exists($this->stateFile)) {
            $this->write($this->emptyState());
        }
    }

    /** 開播時重置聊天室與在線名單 */
    public function reset(): void
    {
        $this->write($this->emptyState());
    }

    /** 觀眾加入房間：建立其收件匣，回傳目前在線人數 */
    public function join(string $viewer): int
    {
        $s = $this->read();
        if (!isset($s['inboxes'][$viewer])) {
            $s['inboxes'][$viewer] = []; // 新觀眾的收件匣
        }
        $this->write($s);
        return count($s['inboxes']);
    }

    /** 觀眾離開：移除其收件匣，回傳目前在線人數 */
    public function leave(string $viewer): int
    {
        $s = $this->read();
        unset($s['inboxes'][$viewer]);
        $this->write($s);
        return count($s['inboxes']);
    }

    /**
     * 送出一則聊天訊息 → fan-out 給房內每位觀眾的收件匣。
     * 回傳本次扇出觸及的觀眾數（呈現 fan-out 放大）。
     */
    public function send(string $user, string $text): int
    {
        $s = $this->read();
        $seq = (int) $s['next_seq'];
        $s['next_seq'] = $seq + 1;
        $msg = [
            'seq' => $seq,
            'user' => $user,
            'text' => $text,
            'ts' => gmdate('H:i:s'),
        ];
        $s['history'][] = $msg;
        // 只保留最近 50 則歷史（直播聊天不必久存）
        if (count($s['history']) > 50) {
            $s['history'] = array_slice($s['history'], -50);
        }

        // ★ fan-out：把訊息複製進房內每一個觀眾的收件匣
        $fanout = 0;
        foreach ($s['inboxes'] as $viewer => $inbox) {
            $inbox[] = $seq;
            $s['inboxes'][$viewer] = array_slice($inbox, -50);
            $fanout++;
        }
        $this->write($s);
        return $fanout; // = 同時在線人數，呈現 1 則訊息扇出給 N 人
    }

    /** 觀眾拉取自己收件匣的訊息（真實系統由 WS 主動推播） */
    public function poll(string $viewer): array
    {
        $s = $this->read();
        $seqs = $s['inboxes'][$viewer] ?? [];
        $byseq = [];
        foreach ($s['history'] as $m) {
            $byseq[$m['seq']] = $m;
        }
        $out = [];
        foreach ($seqs as $seq) {
            if (isset($byseq[$seq])) {
                $out[] = $byseq[$seq];
            }
        }
        return $out;
    }

    /** 同時在線人數（presence） */
    public function viewerCount(): int
    {
        return count($this->read()['inboxes']);
    }

    /** 全房聊天歷史（測試頁顯示用） */
    public function history(): array
    {
        return $this->read()['history'];
    }

    public function viewers(): array
    {
        return array_keys($this->read()['inboxes']);
    }

    private function emptyState(): array
    {
        return ['inboxes' => [], 'history' => [], 'next_seq' => 0];
    }

    private function read(): array
    {
        $fp = fopen($this->stateFile, 'c+');
        flock($fp, LOCK_SH);
        $json = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_decode($json, true) ?: $this->emptyState();
    }

    private function write(array $s): void
    {
        $fp = fopen($this->stateFile, 'c+');
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        $json = json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        fwrite($fp, $json !== false ? $json : '{}');
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
