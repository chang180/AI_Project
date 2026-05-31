<?php
declare(strict_types=1);

/**
 * 追蹤清單（模擬 watches 表 + price→watchers 反向索引）
 * --------------------------------------------------
 * 真實系統：watch 存分片式 DB；另建反向索引 idx:sku -> ZSET(member=watch_id, score=門檻價)。
 *   新價 P 來時，ZRANGEBYSCORE sku P +inf 一次取出所有 target>=P 的 watcher，
 *   把「條件匹配」變成「反向索引 + 區間查」，避免 O(全部 watch) 線性掃描。
 *
 * 這裡用 JSON 檔當「DB」，用一個 process-local 陣列模擬反向索引，
 *   watchersFor() 即模擬 ZRANGEBYSCORE：給定 sku 與現價，回傳所有達標的 watch。
 */
final class Watchlist
{
    private string $file;
    /** @var array<string,array<string,mixed>> watch_id => watch 記錄 */
    private array $watches;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/watches.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, '{}');
        }
        $this->watches = json_decode((string) file_get_contents($this->file), true) ?: [];
    }

    /**
     * 建立追蹤。type=target：低於 threshold 觸發；type=drop_pct：自 base_price 跌 threshold% 觸發。
     * drop_pct 會預先換算成「絕對觸發價 trigger_price = base×(1-pct/100)」，統一成價格區間比較。
     */
    public function add(int $userId, string $sku, string $type, float $threshold, float $basePrice): string
    {
        $watchId = 'w_' . substr(hash('sha256', $userId . $sku . $type . microtime(true)), 0, 8);
        $triggerPrice = $type === 'drop_pct'
            ? round($basePrice * (1 - $threshold / 100), 2)
            : $threshold;

        $this->watches[$watchId] = [
            'watch_id'      => $watchId,
            'user_id'       => $userId,
            'sku'           => $sku,
            'type'          => $type,
            'threshold'     => $threshold,
            'base_price'    => $basePrice,
            'trigger_price' => $triggerPrice, // 反向索引的 score：價格 <= 此值即達標
            'state'         => 'ARMED',        // 去抖狀態機初始狀態
            'cooldown_until' => 0,
            'alerts_sent'   => 0,
            'last_price'    => null,
        ];
        $this->persist();
        return $watchId;
    }

    /**
     * 模擬反向索引區間查（Redis 的 ZRANGEBYSCORE sku <price> +inf 的反向）：
     * 給定 sku 與現價 price，回傳「現價 <= 觸發價」的所有 watch（即達標的 watcher）。
     * 真實系統是 O(log N + 命中數)；這裡為 demo 直接過濾，但語意完全相同——
     *   只挑出達標者，而非把全部 watch 拉出來逐條比大小。
     *
     * @return array<int,array<string,mixed>>
     */
    public function watchersFor(string $sku, float $price): array
    {
        $hits = [];
        foreach ($this->watches as $w) {
            if ($w['sku'] === $sku && $price <= (float) $w['trigger_price']) {
                $hits[] = $w;
            }
        }
        return $hits;
    }

    public function get(string $watchId): ?array
    {
        return $this->watches[$watchId] ?? null;
    }

    public function save(array $watch): void
    {
        $this->watches[$watch['watch_id']] = $watch;
        $this->persist();
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return $this->watches;
    }

    /** 取得某 sku 目前所有的觸發價（示意反向索引內容） */
    public function skus(): array
    {
        return array_values(array_unique(array_map(
            static fn(array $w): string => $w['sku'],
            $this->watches
        )));
    }

    private function persist(): void
    {
        file_put_contents(
            $this->file,
            json_encode($this->watches, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
