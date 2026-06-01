<?php
declare(strict_types=1);

/**
 * SFU（Selective Forwarding Unit）信令 + 媒體轉發決策模擬
 * --------------------------------------------------------------
 * 誠實聲明：本檔不含真實 WebRTC / UDP / 媒體傳輸，純模擬系統設計面試考點：
 *   1) 信令交換  ：peer 加入 room；中繼 SDP（offer/answer）與 ICE candidate。
 *   2) SFU 轉發表：每個 publisher 上行一條流，為每個 subscriber 建一條下行（誰轉給誰）。
 *   3) simulcast ：上行含 high/mid/low 三層，SFU 依「訂閱者頻寬」為其挑層（自動降/升階）。
 *   4) active speaker：各 peer 回報音量，SFU 選出最大音量者為主講者。
 *
 * 共享狀態以單一 JSON 檔保存，所有讀寫以 flock(LOCK_EX) 包住（read-modify-write），
 * 模擬多請求並發下的一致性。執行期資料寫 data/（已 gitignore，自動建目錄）。
 */
final class Sfu
{
    /** simulcast 層定義：層級 → 大致位元率（kbps）。挑層時取「不超過可用頻寬的最高層」。 */
    public const LAYERS = [
        'high' => 1500, // 720p
        'mid'  => 500,  // 360p
        'low'  => 150,  // 180p
    ];

    private string $dbFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dbFile = $dataDir . '/sfu.json';
        if (!file_exists($this->dbFile)) {
            file_put_contents($this->dbFile, json_encode($this->emptyDb()));
        }
    }

    private function emptyDb(): array
    {
        return ['rooms' => []];
    }

    // ============ 對外操作（皆為 read-modify-write，flock 保護） ============

    /** peer 加入 room：建立 room（若無）、配發 peer_id、回傳目前成員。 */
    public function join(string $room, string $name): array
    {
        return $this->mutate(function (array &$db) use ($room, $name): array {
            $r = &$this->ensureRoom($db, $room);
            $peerId = 'p' . (count($r['peers']) + 1);
            // 避免重複（極端情況下用流水號補位）
            while (isset($r['peers'][$peerId])) {
                $peerId = 'p' . (count($r['peers']) + random_int(1, 999));
            }
            $r['peers'][$peerId] = [
                'peer_id'        => $peerId,
                'name'           => $name,
                'bandwidth_kbps' => self::LAYERS['high'], // 預設給足，先吃 high
                'audio_level'    => 0.0,
            ];
            $this->rebuildForwarding($r);
            return ['peer_id' => $peerId, 'peers' => array_keys($r['peers'])];
        });
    }

    /** 中繼 SDP（offer/answer）：存入收件者 to 的信箱，等對端取走。 */
    public function postSdp(string $room, string $from, string $to, string $type, string $sdp): array
    {
        return $this->relay($room, $from, $to, 'sdp', ['type' => $type, 'sdp' => $sdp]);
    }

    /** 中繼 ICE candidate（trickle ICE：邊收集邊送）。 */
    public function postIce(string $room, string $from, string $to, string $candidate): array
    {
        return $this->relay($room, $from, $to, 'ice', ['candidate' => $candidate]);
    }

    /** 取走某 peer 信箱內的信令訊息（取走即清空，模擬投遞）。 */
    public function drainSignals(string $room, string $peer): array
    {
        return $this->mutate(function (array &$db) use ($room, $peer): array {
            if (!isset($db['rooms'][$room]['peers'][$peer])) {
                throw new RuntimeException('peer 不存在');
            }
            $box = $db['rooms'][$room]['mailbox'][$peer] ?? [];
            $db['rooms'][$room]['mailbox'][$peer] = [];
            return ['peer' => $peer, 'signals' => $box];
        });
    }

    /**
     * 設定某訂閱者可用頻寬 → 重算其在轉發表中各下行的 simulcast 層（降/升階）。
     * 回傳該 peer 整體選到的層（取所有下行一致的層級，因頻寬是 per-subscriber）。
     */
    public function setBandwidth(string $room, string $peer, int $kbps): array
    {
        return $this->mutate(function (array &$db) use ($room, $peer, $kbps): array {
            if (!isset($db['rooms'][$room]['peers'][$peer])) {
                throw new RuntimeException('peer 不存在');
            }
            $r = &$db['rooms'][$room];
            $r['peers'][$peer]['bandwidth_kbps'] = $kbps;
            $this->rebuildForwarding($r);
            $layer = self::pickLayer($kbps);
            return ['peer' => $peer, 'kbps' => $kbps, 'layer' => $layer];
        });
    }

    /** 回報音量 → 重新選出主講者（最大音量者）。 */
    public function reportAudio(string $room, string $peer, float $level): array
    {
        return $this->mutate(function (array &$db) use ($room, $peer, $level): array {
            if (!isset($db['rooms'][$room]['peers'][$peer])) {
                throw new RuntimeException('peer 不存在');
            }
            $r = &$db['rooms'][$room];
            $r['peers'][$peer]['audio_level'] = max(0.0, min(1.0, $level));
            $speaker = $this->electSpeaker($r);
            $r['active_speaker'] = $speaker;
            return ['peer' => $peer, 'level' => $r['peers'][$peer]['audio_level'], 'active_speaker' => $speaker];
        });
    }

    /** room 全貌：成員、轉發表（含各下行選到的層）、各訂閱者選層、主講者。 */
    public function roomState(string $room): array
    {
        $db = $this->read();
        if (!isset($db['rooms'][$room])) {
            throw new RuntimeException('room 不存在');
        }
        $r = $db['rooms'][$room];
        $subLayers = [];
        foreach ($r['peers'] as $pid => $p) {
            $subLayers[$pid] = self::pickLayer((int) $p['bandwidth_kbps']);
        }
        return [
            'room'           => $room,
            'peers'          => array_values($r['peers']),
            'forwarding'     => $r['forwarding'] ?? [],
            'subscriber_layers' => $subLayers,
            'active_speaker' => $r['active_speaker'] ?? null,
        ];
    }

    // ============ 純函式：可獨立測試的決策邏輯 ============

    /**
     * 依可用頻寬挑 simulcast 層：取「位元率不超過可用頻寬的最高層」。
     * 頻寬連 low 都不到，仍回 low（至少保住最低畫質，否則該訂閱者全黑）。
     */
    public static function pickLayer(int $kbps): string
    {
        // LAYERS 已由高到低排列
        foreach (self::LAYERS as $layer => $rate) {
            if ($kbps >= $rate) {
                return $layer;
            }
        }
        return 'low';
    }

    /** 從 room 內 peers 選出音量最大者作為主講者；全靜音則回 null。 */
    private function electSpeaker(array $room): ?string
    {
        $best = null;
        $bestLevel = 0.0;
        foreach ($room['peers'] as $pid => $p) {
            $lvl = (float) ($p['audio_level'] ?? 0.0);
            if ($lvl > $bestLevel) {
                $bestLevel = $lvl;
                $best = $pid;
            }
        }
        return $best; // 全部為 0 → null（無人說話）
    }

    /**
     * 重建轉發表：room 內每個 publisher 的上行，為每個其他 subscriber 拉一條下行，
     * 下行的層級依「該 subscriber 的可用頻寬」決定（這就是 SFU 的選層）。
     */
    private function rebuildForwarding(array &$room): void
    {
        $table = [];
        $ids = array_keys($room['peers']);
        foreach ($ids as $pub) {
            foreach ($ids as $sub) {
                if ($pub === $sub) {
                    continue; // 不轉發給自己
                }
                $subKbps = (int) $room['peers'][$sub]['bandwidth_kbps'];
                $table[] = [
                    'publisher'  => $pub,
                    'subscriber' => $sub,
                    'layer'      => self::pickLayer($subKbps),
                ];
            }
        }
        $room['forwarding'] = $table;
    }

    // ============ 共用：信令中繼 + 儲存 ============

    private function relay(string $room, string $from, string $to, string $kind, array $payload): array
    {
        return $this->mutate(function (array &$db) use ($room, $from, $to, $kind, $payload): array {
            if (!isset($db['rooms'][$room]['peers'][$from]) || !isset($db['rooms'][$room]['peers'][$to])) {
                throw new RuntimeException('from/to peer 不存在於該 room');
            }
            $db['rooms'][$room]['mailbox'][$to][] = [
                'from'    => $from,
                'kind'    => $kind,
                'payload' => $payload,
                'ts'      => gmdate('c'),
            ];
            return ['ok' => true, 'delivered_to' => $to, 'kind' => $kind];
        });
    }

    private function &ensureRoom(array &$db, string $room): array
    {
        if (!isset($db['rooms'][$room])) {
            $db['rooms'][$room] = [
                'id'             => $room,
                'created_at'     => gmdate('c'),
                'peers'          => [],
                'mailbox'        => [],
                'forwarding'     => [],
                'active_speaker' => null,
            ];
        }
        $ref = &$db['rooms'][$room];
        return $ref;
    }

    /**
     * read-modify-write：全程 flock(LOCK_EX) 保護，模擬並發一致性。
     * $fn 收到可變的 $db 參照，回傳值即為本次操作結果。
     */
    private function mutate(callable $fn): array
    {
        $fp = fopen($this->dbFile, 'c+');
        if ($fp === false) {
            throw new RuntimeException('無法開啟狀態檔');
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $db = $raw !== '' && $raw !== false ? json_decode($raw, true) : $this->emptyDb();
            if (!is_array($db)) {
                $db = $this->emptyDb();
            }
            $result = $fn($db);
            $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) $json);
            fflush($fp);
            return $result;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function read(): array
    {
        $fp = fopen($this->dbFile, 'r');
        if ($fp === false) {
            return $this->emptyDb();
        }
        try {
            flock($fp, LOCK_SH);
            $raw = stream_get_contents($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        $db = $raw !== '' && $raw !== false ? json_decode($raw, true) : null;
        return is_array($db) ? $db : $this->emptyDb();
    }
}
