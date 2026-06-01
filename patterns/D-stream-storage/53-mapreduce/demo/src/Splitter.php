<?php
declare(strict_types=1);

/**
 * 輸入切片（Input Split）
 * --------------------------------------------------
 * 真實 MapReduce：輸入檔（HDFS/S3）依 block（如 128MB）切成多個 split，
 * 每個 split 餵給一個 map task，達成「資料局部性 + 平行處理」。
 *
 * 這裡把一段純文字依「行」切成 N 個 split（盡量平均），
 * 讓你親眼看到「一份輸入 → 多片 → 多個 map 平行跑」。
 */
final class Splitter
{
    /**
     * @return list<list<string>> 每個元素是一個 split（一批行）
     */
    public static function split(string $text, int $numSplits): array
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];
        $lines = array_values(array_filter($lines, static fn(string $l): bool => trim($l) !== ''));

        $numSplits = max(1, min($numSplits, max(1, count($lines))));
        $splits = array_fill(0, $numSplits, []);

        // round-robin 分配，讓每片行數盡量平均（模擬均勻切塊）
        foreach ($lines as $i => $line) {
            $splits[$i % $numSplits][] = $line;
        }
        return $splits;
    }
}
