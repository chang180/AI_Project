<?php
declare(strict_types=1);

/**
 * Snowflake 唯一 ID 產生器
 * --------------------------------------------------
 * 64-bit 佈局（最高位 sign bit 固定 0，落在 63-bit 正整數範圍內）：
 *
 *   0  | 41 bits 時間戳(ms) | 10 bits 機器 id | 12 bits 序號
 *   ^    ^                    ^                 ^
 *  sign  自訂 epoch 起的毫秒   0..1023           0..4095（同毫秒遞增）
 *
 * 組合公式： id = ((ts - EPOCH) << 22) | (machineId << 12) | seq
 *
 * 核心邏輯：
 *  - 同毫秒序號遞增：同一毫秒內多次取號 → seq++（0..4095）；用滿則自旋等到下一毫秒。
 *  - 時鐘回撥處理：now < lastTs → 小幅回撥等待追上；大幅回撥丟例外。
 *  - 跨請求單調：把 {last_ts, seq} 持久化到 data/ 並用 flock 鎖，
 *    確保多次 HTTP 請求（各自獨立的 PHP process）仍嚴格單調遞增。
 *
 * 注意：PHP 在 x64 平台 int 為 64-bit 有號整數，Snowflake 落在 63-bit
 *       正整數範圍（最大 ~9.2×10^18），不會溢位。
 */
final class Snowflake
{
    /** 自訂 epoch：2024-01-01 00:00:00 UTC 的毫秒數 */
    public const EPOCH = 1704067200000;

    public const TS_BITS      = 41;
    public const MACHINE_BITS = 10;
    public const SEQ_BITS     = 12;

    public const MAX_MACHINE_ID = (1 << self::MACHINE_BITS) - 1; // 1023
    public const MAX_SEQ        = (1 << self::SEQ_BITS) - 1;      // 4095

    public const MACHINE_SHIFT = self::SEQ_BITS;                       // 12
    public const TS_SHIFT      = self::SEQ_BITS + self::MACHINE_BITS;  // 22

    /** 大幅回撥門檻（毫秒）：超過就放棄等待、直接丟例外 */
    private const MAX_BACKWARD_WAIT_MS = 5;

    private int $machineId;
    private string $stateFile;

    public function __construct(int $machineId, string $dataDir)
    {
        if ($machineId < 0 || $machineId > self::MAX_MACHINE_ID) {
            throw new InvalidArgumentException(
                "machineId 必須在 0.." . self::MAX_MACHINE_ID . " 之間，收到 {$machineId}"
            );
        }
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->machineId = $machineId;
        $this->stateFile = $dataDir . '/snowflake_state.json';
        if (!file_exists($this->stateFile)) {
            file_put_contents($this->stateFile, json_encode(['last_ts' => 0, 'seq' => 0]));
        }
    }

    public function machineId(): int
    {
        return $this->machineId;
    }

    /**
     * 產生一個唯一 ID（跨請求單調）。
     *
     * 以 flock(LOCK_EX) 包住「讀狀態 → 推進序號 → 寫狀態」整段臨界區，
     * 模擬單機記憶體中 synchronized 的取號邏輯，但狀態持久化到檔案，
     * 因此即使每個 HTTP 請求是獨立 PHP process 也能維持嚴格單調。
     */
    public function nextId(): int
    {
        return $this->withLock(fn(int &$lastTs, int &$seq): int => $this->advance($lastTs, $seq));
    }

    /**
     * 一次產生 N 個 ID（用於展示同毫秒序號遞增）。
     * 整批只取一次檔案鎖，state 在記憶體中連續推進——因此 burst 內常見
     * 多個 ID 落在同一毫秒、seq 依序遞增（測試頁觀察重點）。
     */
    public function nextIds(int $n): array
    {
        if ($n < 1) {
            return [];
        }
        if ($n > 10000) {
            throw new InvalidArgumentException('一次最多產生 10000 個');
        }
        return $this->withLock(function (int &$lastTs, int &$seq) use ($n): array {
            $ids = [];
            for ($i = 0; $i < $n; $i++) {
                $ids[] = $this->advance($lastTs, $seq);
            }
            return $ids;
        });
    }

    /**
     * 取檔案鎖、讀狀態 → 執行 $body（推進 in-memory state）→ 寫回狀態。
     * $body 收到的 $lastTs/$seq 為參考，可被反覆推進；回傳值原樣回傳。
     *
     * @template T
     * @param callable(int,int):T $body
     * @return T
     */
    private function withLock(callable $body): mixed
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            throw new RuntimeException('無法開啟狀態檔');
        }
        try {
            flock($fp, LOCK_EX);

            $raw = (string) stream_get_contents($fp);
            $state = json_decode($raw, true);
            $lastTs = is_array($state) ? (int) ($state['last_ts'] ?? 0) : 0;
            $seq    = is_array($state) ? (int) ($state['seq'] ?? 0) : 0;

            $result = $body($lastTs, $seq);

            // 寫回最終狀態
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(['last_ts' => $lastTs, 'seq' => $seq]));
            fflush($fp);

            return $result;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * 推進一步：依當前時鐘與 in-memory 的 (lastTs, seq) 算出下一個 ID，
     * 並就地更新 $lastTs/$seq。含同毫秒遞增、序號溢位自旋、時鐘回撥處理。
     */
    private function advance(int &$lastTs, int &$seq): int
    {
        $now = $this->currentMillis();

        if ($now < $lastTs) {
            // ---- 時鐘回撥 ----
            $offset = $lastTs - $now;
            if ($offset <= self::MAX_BACKWARD_WAIT_MS) {
                // 小幅回撥：自旋等待時鐘追上 lastTs
                $now = $this->waitUntil($lastTs);
            } else {
                // 大幅回撥：拒絕發號，明確標示
                throw new RuntimeException(
                    "偵測到時鐘回撥 {$offset}ms（>" . self::MAX_BACKWARD_WAIT_MS
                    . "ms），拒絕發號以保證單調性"
                );
            }
        }

        if ($now === $lastTs) {
            // 同一毫秒：序號遞增
            $seq = ($seq + 1) & self::MAX_SEQ;
            if ($seq === 0) {
                // 序號用滿 → 自旋等到下一毫秒
                $now = $this->waitUntil($lastTs + 1);
            }
        } else {
            // 進入新的毫秒：序號歸零
            $seq = 0;
        }

        $lastTs = $now;

        return (($now - self::EPOCH) << self::TS_SHIFT)
            | ($this->machineId << self::MACHINE_SHIFT)
            | $seq;
    }

    /** 把 ID 還原成各欄位，驗證雙向。 */
    public static function decode(int $id): array
    {
        $seq       = $id & self::MAX_SEQ;
        $machineId = ($id >> self::MACHINE_SHIFT) & self::MAX_MACHINE_ID;
        $tsOffset  = $id >> self::TS_SHIFT;
        $timestamp = $tsOffset + self::EPOCH; // 還原成 Unix 毫秒

        $sec = intdiv($timestamp, 1000);
        $ms  = $timestamp % 1000;

        return [
            'id'         => $id,
            'timestamp'  => $timestamp, // Unix 毫秒
            'datetime'   => gmdate('Y-m-d H:i:s', $sec) . sprintf('.%03d UTC', $ms),
            'machineId'  => $machineId,
            'seq'        => $seq,
        ];
    }

    /** 當前 Unix 毫秒 */
    protected function currentMillis(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** 自旋等待，直到時鐘到達 $targetMs，回傳到達後的毫秒值 */
    private function waitUntil(int $targetMs): int
    {
        $now = $this->currentMillis();
        while ($now < $targetMs) {
            // 短暫讓出 CPU，避免純忙等
            usleep(100);
            $now = $this->currentMillis();
        }
        return $now;
    }
}
