<?php
declare(strict_types=1);

/**
 * 一致性雜湊環（Consistent Hash Ring）+ 虛擬節點（vnode）
 * --------------------------------------------------------------
 * 為什麼要一致性雜湊？
 *   傳統 hash(key) % N，一旦 N 變動（加/移除節點），幾乎所有 key 都要重映射 → 雪崩。
 *   一致性雜湊把節點與 key 都映射到同一個「環」（0 ~ 2^32-1）上；
 *   key 順時針找到第一個節點。加/移除節點時，只有「相鄰一段」的 key 受影響。
 *
 * 為什麼要虛擬節點（vnode）？
 *   每個實體節點只放 1 個點時，環上分佈不均 → 資料傾斜（某節點扛太多）。
 *   讓每個實體節點放 V 個 vnode（hash("node#0"), hash("node#1") ...），
 *   均勻散佈在環上，負載更平均；移除節點時其負擔也較平均地分給其它節點。
 *
 * 雜湊：本環境僅允許 json + PDO + hash 擴充，這裡用 crc32 取得 32-bit 環座標。
 */
final class ConsistentHashRing
{
    /** 每個實體節點的虛擬節點數量 */
    private int $vnodes;

    /** @var array<int,string> 環：座標(point) => 實體節點名稱，依座標排序 */
    private array $ring = [];

    /** @var int[] 已排序的環座標，供二分搜尋順時針查找 */
    private array $sortedPoints = [];

    /** @var array<string,bool> 已加入環的實體節點集合 */
    private array $nodes = [];

    public function __construct(int $vnodes = 16)
    {
        $this->vnodes = max(1, $vnodes);
    }

    /** 把字串映射到 32-bit 環座標 */
    public function hash(string $s): int
    {
        // crc32 在 64-bit PHP 可能回傳負數，用 & 0xFFFFFFFF 轉成無號
        return crc32($s) & 0xFFFFFFFF;
    }

    /** vnode 標籤：node 名稱 + #i */
    private function vnodeKey(string $node, int $i): string
    {
        return $node . '#' . $i;
    }

    public function addNode(string $node): void
    {
        if (isset($this->nodes[$node])) {
            return;
        }
        $this->nodes[$node] = true;
        for ($i = 0; $i < $this->vnodes; $i++) {
            $point = $this->hash($this->vnodeKey($node, $i));
            // 極小機率碰撞：線性探測避免覆蓋
            while (isset($this->ring[$point])) {
                $point = ($point + 1) & 0xFFFFFFFF;
            }
            $this->ring[$point] = $node;
        }
        $this->rebuild();
    }

    public function removeNode(string $node): void
    {
        if (!isset($this->nodes[$node])) {
            return;
        }
        unset($this->nodes[$node]);
        foreach ($this->ring as $point => $owner) {
            if ($owner === $node) {
                unset($this->ring[$point]);
            }
        }
        $this->rebuild();
    }

    private function rebuild(): void
    {
        ksort($this->ring);
        $this->sortedPoints = array_keys($this->ring);
    }

    /** @return string[] 目前所有實體節點名稱 */
    public function nodes(): array
    {
        return array_keys($this->nodes);
    }

    /** @return array<int,string> 環上 (座標 => 實體節點)，已排序 */
    public function ring(): array
    {
        return $this->ring;
    }

    /**
     * key 順時針找到第一個 vnode，回傳其實體節點。
     * 用二分搜尋找「第一個 >= keyPoint 的座標」，找不到則環繞回第一個。
     */
    public function locate(string $key): ?string
    {
        if ($this->sortedPoints === []) {
            return null;
        }
        $kp = $this->hash($key);
        $idx = $this->firstGE($kp);
        $point = $this->sortedPoints[$idx];
        return $this->ring[$point];
    }

    /**
     * preference list：key 順時針依序取「前 N 個『不同實體節點』」當副本。
     * 跳過同一實體節點的其它 vnode，確保 N 個副本落在 N 台不同機器。
     * @return string[]
     */
    public function preferenceList(string $key, int $n): array
    {
        if ($this->sortedPoints === []) {
            return [];
        }
        $count = count($this->sortedPoints);
        $n = min($n, count($this->nodes));
        $kp = $this->hash($key);
        $start = $this->firstGE($kp);

        $result = [];
        $seen = [];
        for ($step = 0; $step < $count && count($result) < $n; $step++) {
            $point = $this->sortedPoints[($start + $step) % $count];
            $node = $this->ring[$point];
            if (!isset($seen[$node])) {
                $seen[$node] = true;
                $result[] = $node;
            }
        }
        return $result;
    }

    /** 二分搜尋：sortedPoints 中第一個 >= $target 的索引；超過尾端則環繞回 0 */
    private function firstGE(int $target): int
    {
        $lo = 0;
        $hi = count($this->sortedPoints) - 1;
        if ($target > $this->sortedPoints[$hi]) {
            return 0; // 環繞
        }
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->sortedPoints[$mid] >= $target) {
                $hi = $mid;
            } else {
                $lo = $mid + 1;
            }
        }
        return $lo;
    }

    /**
     * 計算「目前每個 key 落在哪個實體節點」的對映，供示範重映射比例用。
     * @param string[] $keys
     * @return array<string,string> key => node
     */
    public function mapKeys(array $keys): array
    {
        $map = [];
        foreach ($keys as $k) {
            $map[$k] = (string) $this->locate($k);
        }
        return $map;
    }
}
