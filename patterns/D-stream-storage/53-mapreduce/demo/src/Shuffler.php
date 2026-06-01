<?php
declare(strict_types=1);

/**
 * Shuffle / Sort 階段（整個 MapReduce 的瓶頸所在）
 * --------------------------------------------------
 * 把所有 map task 的中間對「依 key 分組」，並決定每個 key 要送到哪個 reducer：
 *   partition = hash(詞) % R     （R = reducer 數）
 *
 * 保證「同一個詞一定匯到同一個 reducer」，否則加總會分散、算錯。
 * 真實系統這一步要跨網路把資料從每個 mapper 拉到每個 reducer（all-to-all），
 * 是最耗網路頻寬的環節，也是資料傾斜（某個熱門 key 爆量）的引爆點。
 *
 * 用 hash() 而非 crc32/md5 之外的擴充：本檔僅用內建 hash（crc32b）取模。
 */
final class Shuffler
{
    /**
     * @param list<array{0:string,1:int}> $allPairs 所有 mapper 的中間對
     * @param int $numReducers R
     * @return array{
     *   partitions: array<int, array<string, list<int>>>,
     *   assignment: array<string, int>
     * }
     *   partitions[r][詞] = [該詞收集到的所有 value]
     *   assignment[詞]    = 該詞被分到的 reducer 編號
     */
    public static function shuffle(array $allPairs, int $numReducers): array
    {
        $numReducers = max(1, $numReducers);
        $partitions = [];
        $assignment = [];

        foreach ($allPairs as [$word, $value]) {
            $r = self::partition($word, $numReducers);
            $assignment[$word] = $r;
            $partitions[$r][$word][] = $value;
        }

        // 依 reducer 編號排序、組內依 key 排序（模擬 sort 階段，輸出穩定好讀）
        ksort($partitions);
        foreach ($partitions as $r => $groups) {
            ksort($groups);
            $partitions[$r] = $groups;
        }
        ksort($assignment);

        return ['partitions' => $partitions, 'assignment' => $assignment];
    }

    /** partition function：hash(詞) % R，決定詞分到哪個 reducer。 */
    public static function partition(string $word, int $numReducers): int
    {
        // 內建 hash()，crc32b 取無號整數後取模
        $h = hexdec(hash('crc32b', $word));
        return (int) ($h % $numReducers);
    }
}
