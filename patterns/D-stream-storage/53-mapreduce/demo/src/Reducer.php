<?php
declare(strict_types=1);

/**
 * Reduce 階段
 * --------------------------------------------------
 * 每個 reducer 拿到「分給它的那些詞 + 各自的 value 清單」，
 * 對每個詞把 value 加總，輸出 (詞, 總數)。
 *
 * word count 的 reduce 就是 sum；換成其他 job（如平均、Top-N）
 * 只要換掉這個聚合函式，map/shuffle 完全不動 —— 這就是 MapReduce 的通用性。
 */
final class Reducer
{
    /**
     * 對單一 partition（一個 reducer 負責的所有詞）跑 reduce。
     *
     * @param array<string, list<int>> $partition  詞 => value 清單
     * @return array<string, int> 詞 => 總數
     */
    public static function reduce(array $partition): array
    {
        $out = [];
        foreach ($partition as $word => $values) {
            $out[$word] = array_sum($values);   // 聚合：sum
        }
        ksort($out);
        return $out;
    }
}
