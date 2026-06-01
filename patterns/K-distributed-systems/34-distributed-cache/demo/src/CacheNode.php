<?php
declare(strict_types=1);

/**
 * CacheNode — 單一 shard（含 master + replica 副本）
 * ----------------------------------------------------
 * 真實 Redis Cluster：每個 shard 是一個 master + N 個 replica；
 *   寫走 master，master 以非同步複製把資料推給 replica；master 掛掉時 replica 被選為新 master（故障轉移）。
 *
 * 本檔案實作的「真實邏輯」：
 *   - master 儲存 key → {value, expireAt, lastAccess, freq}
 *   - replica：master 每次寫入後同步一份（教學版用「同步覆寫」模擬非同步複製，並記錄複製延遲位元）
 *   - TTL：到期視為不存在（惰性過期 lazy expire）
 *   - maxmemory 滿時淘汰：LRU（最久未用）或 LFU（最少使用次數）兩種策略可切換，回報淘汰了誰
 *
 * 每個 shard 一個 JSON 檔，flock 保護。
 */
final class CacheNode
{
    private string $file;
    private string $name;
    private int $maxKeys;        // 以「鍵數」近似 maxmemory（教學版簡化）
    private string $policy;      // 'lru' | 'lfu' | 'noeviction'

    public function __construct(string $dataDir, string $name, int $maxKeys = 100, string $policy = 'lru')
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->name = preg_replace('/[^0-9A-Za-z_\-]/', '', $name) ?: 'shard';
        $this->file = rtrim($dataDir, '/\\') . '/node_' . $this->name . '.json';
        $this->maxKeys = max(1, $maxKeys);
        $this->policy = in_array($policy, ['lru', 'lfu', 'noeviction'], true) ? $policy : 'lru';
        if (!file_exists($this->file)) {
            $this->writeRaw(['master' => [], 'replica' => [], 'replLag' => 0]);
        }
    }

    public function setPolicy(string $policy, int $maxKeys): void
    {
        $this->policy = in_array($policy, ['lru', 'lfu', 'noeviction'], true) ? $policy : 'lru';
        $this->maxKeys = max(1, $maxKeys);
    }

    /**
     * 寫入（走 master），可帶 TTL（秒）。寫滿時依策略淘汰。
     * @return array{stored:bool,evicted:?array}
     */
    public function set(string $key, mixed $value, ?int $ttl = null): array
    {
        $fp = $this->lock();
        try {
            $state = $this->read($fp);
            $now = $this->now();

            $evicted = null;
            // 若是新 key 且已達上限 → 先淘汰一個
            if (!isset($state['master'][$key]) && count($state['master']) >= $this->maxKeys) {
                if ($this->policy === 'noeviction') {
                    return ['stored' => false, 'evicted' => null, 'reason' => 'maxmemory 已滿且策略為 noeviction，拒絕寫入'];
                }
                $victim = $this->pickVictim($state['master']);
                if ($victim !== null) {
                    $evicted = ['key' => $victim, 'meta' => $state['master'][$victim]];
                    unset($state['master'][$victim]);
                }
            }

            $state['master'][$key] = [
                'value' => $value,
                'expireAt' => $ttl !== null ? $now + $ttl : null,
                'lastAccess' => $now,
                'freq' => $state['master'][$key]['freq'] ?? 1,
            ];

            // 同步複製到 replica（教學版：寫後立即同步一份，並記一個複製延遲標記）
            $state['replica'] = $state['master'];
            $state['replLag'] = 0;

            $this->write($fp, $state);
            return ['stored' => true, 'evicted' => $evicted];
        } finally {
            $this->unlock($fp);
        }
    }

    /**
     * 讀取（master）。會更新 lastAccess / freq（給 LRU/LFU 用）。
     * 過期或不存在回 null。
     */
    public function get(string $key): mixed
    {
        $fp = $this->lock();
        try {
            $state = $this->read($fp);
            $entry = $state['master'][$key] ?? null;
            if ($entry === null) {
                return null;
            }
            $now = $this->now();
            if ($entry['expireAt'] !== null && $entry['expireAt'] <= $now) {
                // 惰性過期：到期即移除
                unset($state['master'][$key]);
                $state['replica'] = $state['master'];
                $this->write($fp, $state);
                return null;
            }
            // 命中 → 更新 LRU/LFU 統計
            $state['master'][$key]['lastAccess'] = $now;
            $state['master'][$key]['freq'] = ($entry['freq'] ?? 0) + 1;
            $this->write($fp, $state);
            return $entry['value'];
        } finally {
            $this->unlock($fp);
        }
    }

    /** 只讀檢查（不更新統計），給穿透/雪崩示範判斷用 */
    public function peekExists(string $key): bool
    {
        $state = $this->readFile();
        $entry = $state['master'][$key] ?? null;
        if ($entry === null) {
            return false;
        }
        if ($entry['expireAt'] !== null && $entry['expireAt'] <= $this->now()) {
            return false;
        }
        return true;
    }

    public function delete(string $key): void
    {
        $fp = $this->lock();
        try {
            $state = $this->read($fp);
            unset($state['master'][$key]);
            $state['replica'] = $state['master'];
            $this->write($fp, $state);
        } finally {
            $this->unlock($fp);
        }
    }

    /** shard 概觀：鍵數、上限、策略、複製狀態、各鍵 meta（給展示） */
    public function info(): array
    {
        $state = $this->readFile();
        $now = $this->now();
        $keys = [];
        foreach ($state['master'] as $k => $e) {
            $ttl = $e['expireAt'] === null ? null : max(0, $e['expireAt'] - $now);
            $keys[] = [
                'key' => $k,
                'ttl' => $ttl,
                'freq' => $e['freq'] ?? 0,
                'idle' => $now - ($e['lastAccess'] ?? $now),
                'expired' => $e['expireAt'] !== null && $e['expireAt'] <= $now,
            ];
        }
        return [
            'node' => $this->name,
            'keys' => count($state['master']),
            'maxKeys' => $this->maxKeys,
            'policy' => $this->policy,
            'replicaKeys' => count($state['replica'] ?? []),
            'replLag' => $state['replLag'] ?? 0,
            'detail' => $keys,
        ];
    }

    /** 故障轉移示範：replica 升為 master（把 replica 內容當作新 master） */
    public function failover(): array
    {
        $fp = $this->lock();
        try {
            $state = $this->read($fp);
            $promoted = count($state['replica'] ?? []);
            $state['master'] = $state['replica'] ?? [];
            $this->write($fp, $state);
            return ['node' => $this->name, 'promotedKeys' => $promoted, 'note' => 'replica 已升為 master（故障轉移）'];
        } finally {
            $this->unlock($fp);
        }
    }

    // ============ 淘汰策略 ============

    /**
     * 依當前策略挑選要淘汰的 key。
     *   lru：lastAccess 最小（最久未存取）
     *   lfu：freq 最小（最少使用），平手再比 lastAccess
     * 並優先淘汰「已過期」的 key（與 Redis 行為一致：過期者最該走）。
     * @param array<string,array> $master
     */
    private function pickVictim(array $master): ?string
    {
        if ($master === []) {
            return null;
        }
        $now = $this->now();

        // 1) 優先淘汰已過期者
        foreach ($master as $k => $e) {
            if ($e['expireAt'] !== null && $e['expireAt'] <= $now) {
                return $k;
            }
        }

        // 2) 依策略
        $victim = null;
        $best = null;
        foreach ($master as $k => $e) {
            if ($this->policy === 'lfu') {
                $score = [$e['freq'] ?? 0, $e['lastAccess'] ?? 0]; // freq 優先，再比 idle
            } else { // lru（含 noeviction 退化時不會走到這）
                $score = [$e['lastAccess'] ?? 0, $e['freq'] ?? 0];
            }
            if ($best === null || $this->lessThan($score, $best)) {
                $best = $score;
                $victim = $k;
            }
        }
        return $victim;
    }

    /** 字典序比較兩個 score 陣列 */
    private function lessThan(array $a, array $b): bool
    {
        foreach ($a as $i => $v) {
            if ($v < ($b[$i] ?? PHP_INT_MAX)) {
                return true;
            }
            if ($v > ($b[$i] ?? PHP_INT_MAX)) {
                return false;
            }
        }
        return false;
    }

    // ============ 持久化 ============

    private function now(): int
    {
        return time();
    }

    private function lock()
    {
        $fp = fopen($this->file, 'c+');
        if ($fp === false) {
            throw new RuntimeException('無法開啟 shard 檔');
        }
        flock($fp, LOCK_EX);
        return $fp;
    }

    private function unlock($fp): void
    {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /** 從已持有鎖的檔案指標讀取（呼叫端在 lock() 之後） */
    private function read($fp): array
    {
        rewind($fp);
        $raw = '';
        while (($chunk = fread($fp, 8192)) !== false && $chunk !== '') {
            $raw .= $chunk;
        }
        return $this->decode($raw);
    }

    /** 不持鎖的唯讀（給 peekExists / info） */
    private function readFile(): array
    {
        return $this->decode((string) @file_get_contents($this->file));
    }

    private function decode(string $raw): array
    {
        $arr = json_decode($raw, true);
        if (!is_array($arr) || !isset($arr['master'])) {
            return ['master' => [], 'replica' => [], 'replLag' => 0];
        }
        $arr['replica'] ??= [];
        $arr['replLag'] ??= 0;
        return $arr;
    }

    /**
     * 寫回已持有鎖的檔案指標（truncate + rewind + write），避免在 Windows 上
     * 對仍被 flock 開啟的檔案做第二次開檔/rename 而被拒。
     */
    private function write($fp, array $state): void
    {
        $json = (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
    }

    private function writeRaw(array $state): void
    {
        file_put_contents(
            $this->file,
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
