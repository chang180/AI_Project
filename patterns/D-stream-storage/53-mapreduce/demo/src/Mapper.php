<?php
declare(strict_types=1);

/**
 * Map 階段
 * --------------------------------------------------
 * 每個 map task 吃一個 input split，對每一行的每個詞輸出 (詞, 1)。
 * 這是 word count 的經典 map：把輸入轉成大量 (key, value) 中間對。
 *
 * 真實系統：map 在「資料所在的節點」上跑（move compute to data），
 * 輸出寫到本地磁碟，等 shuffle 來拉。
 *
 * 可選 combiner：在 map 端先做一次本地預聚合（同詞先加總），
 * 大幅減少 shuffle 要搬運的資料量。
 */
final class Mapper
{
    /**
     * 對單一 split 跑 map。
     *
     * @param list<string> $split
     * @param bool $combiner 是否在 map 端做本地預聚合
     * @return list<array{0:string,1:int}> 中間對 (詞, 計數)
     */
    public static function map(array $split, bool $combiner = false): array
    {
        $pairs = [];
        foreach ($split as $line) {
            foreach (self::tokenize($line) as $word) {
                $pairs[] = [$word, 1];
            }
        }

        if (!$combiner) {
            return $pairs;
        }

        // combiner：本地先把同詞加總，減少 shuffle 流量
        $local = [];
        foreach ($pairs as [$word, $cnt]) {
            $local[$word] = ($local[$word] ?? 0) + $cnt;
        }
        $out = [];
        foreach ($local as $word => $cnt) {
            $out[] = [(string) $word, $cnt];
        }
        return $out;
    }

    /**
     * 斷詞：小寫化 + 只留字母數字（教學用，足以展示分組）。
     *
     * @return list<string>
     */
    public static function tokenize(string $line): array
    {
        $line = strtolower($line);
        $words = preg_split('/[^a-z0-9\x{4e00}-\x{9fff}]+/u', $line) ?: [];
        return array_values(array_filter($words, static fn(string $w): bool => $w !== ''));
    }
}
