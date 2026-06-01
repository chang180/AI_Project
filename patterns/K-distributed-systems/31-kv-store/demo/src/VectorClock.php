<?php
declare(strict_types=1);

/**
 * 向量時鐘（Vector Clock）
 * --------------------------------------------------------------
 * 用途：在去中心化、最終一致的系統中判斷兩個版本的「因果關係」。
 *   - 每筆 value 帶一個 map：node => counter。
 *   - 協調者每次寫入時，把「自己」這個 node 的 counter +1。
 *   - 比較兩個向量時鐘 A、B：
 *       A == B            完全相同
 *       A 「happens-before」B（A < B）：B 的每個分量都 >= A，且至少一個 >    → B 較新，丟掉 A
 *       B < A             A 較新
 *       否則「並發 (concurrent)」：兩邊各有對方沒有的更新 → 衝突，保留為 sibling
 *
 * 為何不直接用 last-write-wins(LWW) 時間戳？
 *   LWW 簡單，但時鐘不同步或真正並發時會「靜默丟寫」。
 *   向量時鐘能偵測並發，把衝突暴露給應用層解決（Dynamo 的購物車合併即此思路）。
 *
 * 比較結果常數
 */
final class VectorClock
{
    public const EQUAL = 0;
    public const BEFORE = 1;   // $a happens-before $b（$a 較舊）
    public const AFTER = 2;    // $a happens-after  $b（$a 較新）
    public const CONCURRENT = 3;

    /**
     * 複製並把 $node 的 counter +1（協調者寫入時呼叫）
     * @param array<string,int> $clock
     * @return array<string,int>
     */
    public static function increment(array $clock, string $node): array
    {
        $clock[$node] = ($clock[$node] ?? 0) + 1;
        return $clock;
    }

    /**
     * 比較兩個向量時鐘
     * @param array<string,int> $a
     * @param array<string,int> $b
     */
    public static function compare(array $a, array $b): int
    {
        $aGreater = false; // 存在某分量 a > b
        $bGreater = false; // 存在某分量 b > a

        $nodes = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($nodes as $node) {
            $av = $a[$node] ?? 0;
            $bv = $b[$node] ?? 0;
            if ($av > $bv) {
                $aGreater = true;
            } elseif ($bv > $av) {
                $bGreater = true;
            }
        }

        if (!$aGreater && !$bGreater) {
            return self::EQUAL;
        }
        if ($aGreater && !$bGreater) {
            return self::AFTER;     // a 包含 b 的全部因果，且更多 → a 較新
        }
        if (!$aGreater && $bGreater) {
            return self::BEFORE;    // b 較新
        }
        return self::CONCURRENT;    // 各有對方沒有的 → 並發衝突
    }

    /**
     * 兩個時鐘逐分量取 max（解決衝突、合併 sibling 時用）
     * @param array<string,int> $a
     * @param array<string,int> $b
     * @return array<string,int>
     */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $node => $v) {
            $a[$node] = max($a[$node] ?? 0, $v);
        }
        return $a;
    }

    /** 人類可讀字串，如 "{A:1, B:2}" */
    public static function toString(array $clock): string
    {
        ksort($clock);
        $parts = [];
        foreach ($clock as $node => $v) {
            $parts[] = $node . ':' . $v;
        }
        return '{' . implode(', ', $parts) . '}';
    }
}
