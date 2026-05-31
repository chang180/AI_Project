<?php
declare(strict_types=1);

require_once __DIR__ . '/TokenBucket.php';
require_once __DIR__ . '/SlidingWindowCounter.php';

/**
 * 分散式限流器 — per-key 狀態管理 + 原子放行決策
 * --------------------------------------------------
 * 真實系統：所有 Gateway 節點共用一份 Redis；用 INCR/EXPIRE 或 Lua 腳本
 *           做原子的「讀-改-寫」，避免多節點各算各的造成超賣放行。
 * 這裡：用 JSON 檔當共享狀態，用 flock(LOCK_EX) 把整段「讀狀態→跑演算法→
 *       寫回狀態」鎖成單一原子操作，模擬 Redis 的原子性（單機示意）。
 *
 * 對外只暴露 allow(key)：回傳是否放行、剩餘額度、Retry-After。
 * 可切換演算法（token_bucket / sliding_window），呼應「同一介面、不同策略」。
 */
final class RateLimiter
{
    private string $stateFile;
    private string $configFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->stateFile  = $dataDir . '/limiter_state.json';
        $this->configFile = $dataDir . '/limiter_config.json';
        if (!file_exists($this->stateFile)) {
            file_put_contents($this->stateFile, '{}');
        }
        if (!file_exists($this->configFile)) {
            // 預設規則：每 10 秒 5 次，演算法為令牌桶
            file_put_contents($this->configFile, json_encode([
                'algo'       => 'token_bucket',
                'limit'      => 5,
                'window_sec' => 10,
            ]));
        }
    }

    /** 取得目前規則設定 */
    public function getConfig(): array
    {
        $json = (string) file_get_contents($this->configFile);
        return json_decode($json, true) ?: ['algo' => 'token_bucket', 'limit' => 5, 'window_sec' => 10];
    }

    /** 設定規則（如「每 window_sec 秒 limit 次」），並清空既有狀態重新開始 */
    public function setConfig(string $algo, int $limit, int $windowSec): void
    {
        $algo = in_array($algo, ['token_bucket', 'sliding_window'], true) ? $algo : 'token_bucket';
        file_put_contents($this->configFile, json_encode([
            'algo'       => $algo,
            'limit'      => max(1, $limit),
            'window_sec' => max(1, $windowSec),
        ]));
        file_put_contents($this->stateFile, '{}'); // 改規則 → 重置所有 key 的狀態
    }

    /**
     * 核心：判斷某 key 的這次請求是否放行。
     * 整段「讀-改-寫」由 flock 原子化，模擬 Redis 原子操作。
     *
     * @return array{allowed:bool,remaining:int,retry_after:int,algo:string,limit:int,window_sec:int}
     */
    public function allow(string $key): array
    {
        $cfg = $this->getConfig();
        $now = microtime(true);

        // 依規則建立對應演算法
        if ($cfg['algo'] === 'sliding_window') {
            $algo = new SlidingWindowCounter((int) $cfg['limit'], (int) $cfg['window_sec']);
        } else {
            // 令牌桶：容量 = limit，補充速率 = limit / window_sec（每秒）
            $algo = new TokenBucket((float) $cfg['limit'], (float) $cfg['limit'] / (float) $cfg['window_sec']);
        }

        // ===== 原子區塊開始（flock 模擬 Redis 原子性）=====
        $fp = fopen($this->stateFile, 'c+');
        flock($fp, LOCK_EX);

        $raw = stream_get_contents($fp);
        $all = json_decode((string) $raw, true) ?: [];
        $keyState = $all[$key][$cfg['algo']] ?? null;

        $result = $algo->tryConsume($keyState, $now);

        $all[$key][$cfg['algo']] = $result['state'];

        // 覆寫整個狀態檔
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        // ===== 原子區塊結束 =====

        return [
            'allowed'     => $result['allowed'],
            'remaining'   => $result['remaining'],
            'retry_after' => $result['retry_after'],
            'algo'        => $cfg['algo'],
            'limit'       => (int) $cfg['limit'],
            'window_sec'  => (int) $cfg['window_sec'],
        ];
    }

    /** 重置單一 key（或全部）的狀態 */
    public function reset(?string $key = null): void
    {
        if ($key === null) {
            file_put_contents($this->stateFile, '{}');
            return;
        }
        $fp = fopen($this->stateFile, 'c+');
        flock($fp, LOCK_EX);
        $all = json_decode((string) stream_get_contents($fp), true) ?: [];
        unset($all[$key]);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
