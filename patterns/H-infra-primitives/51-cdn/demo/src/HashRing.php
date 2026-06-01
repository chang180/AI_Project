<?php
declare(strict_types=1);

/**
 * 一致性雜湊環（Consistent Hashing Ring）
 * --------------------------------------------------
 * 用途：把「資產」對應到「邊緣節點」。
 *
 * 為什麼用一致性雜湊？
 *   最直覺的做法是 hash(asset) % N（N=節點數）。但只要新增/移除一個邊緣節點，
 *   N 一變，幾乎「所有」資產的對應都會改變 → 整個 CDN 快取大規模失效、回源洪峰。
 *   一致性雜湊把節點與資產都映射到同一個環上，新增/移除節點時，只有「相鄰一段」
 *   的資產需要搬移，其餘不動 → 快取擾動最小。
 *
 * 虛擬節點（virtual nodes / replicas）：
 *   每個實體節點在環上放多個虛擬點，讓資產分布更平均，避免熱點集中在某一節點。
 *
 * 註：本檔禁用 mb_* / openssl_*，僅用 PHP 內建 hash('crc32b', ...) 取雜湊值。
 */
final class HashRing
{
    /** @var array<int,string> 環：雜湊點 → 實體節點名 */
    private array $ring = [];
    /** @var int[] 已排序的環上雜湊點 */
    private array $sortedKeys = [];

    /**
     * @param string[] $nodes  實體邊緣節點名稱（如各區域 PoP）
     * @param int      $replicas 每個實體節點的虛擬節點數
     */
    public function __construct(array $nodes, private int $replicas = 64)
    {
        foreach ($nodes as $node) {
            $this->addNode($node);
        }
    }

    public function addNode(string $node): void
    {
        for ($i = 0; $i < $this->replicas; $i++) {
            $point = $this->hash($node . '#' . $i);
            $this->ring[$point] = $node;
        }
        $this->rebuild();
    }

    public function removeNode(string $node): void
    {
        for ($i = 0; $i < $this->replicas; $i++) {
            $point = $this->hash($node . '#' . $i);
            unset($this->ring[$point]);
        }
        $this->rebuild();
    }

    /**
     * 取得某資產 key 應落在哪個實體節點：
     * 在環上順時針找「第一個 >= hash(key)」的虛擬點，環尾則繞回環首。
     */
    public function getNode(string $key): string
    {
        if ($this->sortedKeys === []) {
            throw new RuntimeException('hash ring is empty');
        }
        $h = $this->hash($key);
        foreach ($this->sortedKeys as $point) {
            if ($h <= $point) {
                return $this->ring[$point];
            }
        }
        // 繞回環首
        return $this->ring[$this->sortedKeys[0]];
    }

    /** 32 位元無號雜湊（crc32b），作為環上的座標 */
    private function hash(string $s): int
    {
        return (int) hexdec(hash('crc32b', $s));
    }

    private function rebuild(): void
    {
        $this->sortedKeys = array_keys($this->ring);
        sort($this->sortedKeys);
    }
}
