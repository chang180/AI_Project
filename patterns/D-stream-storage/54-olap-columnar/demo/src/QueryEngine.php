<?php
declare(strict_types=1);

/**
 * 向量化查詢引擎（Vectorized Query Engine）
 * --------------------------------------------------
 * 執行：SELECT <agg>(<select欄>) ... GROUP BY <groupby欄>
 *
 * 列式儲存的關鍵威力：分析查詢「只掃用到的欄」。
 *   例：SELECT SUM(amount) GROUP BY region
 *       → 只需要 amount、region 兩欄，其他欄（ts、device）完全不讀。
 *
 * 同時計算「掃描量對比」，這是本題最核心的考點：
 *   - 列式掃描量 = 只算「用到的欄」的格子數
 *   - 行式掃描量 = 必須讀「整列的所有欄」的格子數（即 列數 × 全部欄數）
 *   兩者相除 = 列式省下的 I/O 倍數（欄越多、用到的越少，差距越大）。
 *
 * 「向量化」：對整欄陣列做一次性批次運算（迴圈跑在連續陣列上、無分支跳列），
 * 比行式「一列一列拆欄、逐筆 if」對 CPU cache 友善得多。
 */
final class QueryEngine
{
    public function __construct(private ColumnStore $store) {}

    /**
     * @param string $selectCol  被聚合的欄（agg 作用對象，count(*) 時可給 ''）
     * @param string $groupByCol 分組欄（'' 表示不分組，全表聚合）
     * @param string $agg        sum | avg | count | min | max
     * @return array 含結果 groups 與掃描量對比 scan
     */
    public function run(string $selectCol, string $groupByCol, string $agg): array
    {
        $agg = strtolower($agg);
        $rowCount = $this->store->rowCount();
        $totalCols = count($this->store->columnNames());

        // 1) 算出「這個查詢真正用到哪些欄」
        $usedCols = [];
        if ($groupByCol !== '') {
            $usedCols[$groupByCol] = true;
        }
        if ($agg !== 'count' && $selectCol !== '') {
            $usedCols[$selectCol] = true;
        }
        // count(*) 不需任何資料欄；至少掃 1 �if 沒有其他欄（取列數）
        $usedColNames = array_keys($usedCols);

        // 2) 只抓需要的欄（列式：其他欄一個 byte 都不讀）
        $groupVals  = $groupByCol !== '' ? $this->store->column($groupByCol) : [];
        $valueVals  = ($agg !== 'count' && $selectCol !== '') ? $this->store->column($selectCol) : [];

        // 3) 向量化聚合：在連續陣列上一次跑完
        $acc = []; // key → ['sum'=>, 'cnt'=>, 'min'=>, 'max'=>]
        for ($i = 0; $i < $rowCount; $i++) {
            $key = $groupByCol !== '' ? (string) $groupVals[$i] : '__ALL__';
            if (!isset($acc[$key])) {
                $acc[$key] = ['sum' => 0.0, 'cnt' => 0, 'min' => INF, 'max' => -INF];
            }
            $acc[$key]['cnt']++;
            if ($agg !== 'count' && $selectCol !== '') {
                $v = (float) $valueVals[$i];
                $acc[$key]['sum'] += $v;
                if ($v < $acc[$key]['min']) { $acc[$key]['min'] = $v; }
                if ($v > $acc[$key]['max']) { $acc[$key]['max'] = $v; }
            }
        }

        // 4) 收斂成結果
        $groups = [];
        foreach ($acc as $key => $a) {
            $val = match ($agg) {
                'sum'   => round($a['sum'], 2),
                'avg'   => $a['cnt'] ? round($a['sum'] / $a['cnt'], 2) : 0,
                'count' => $a['cnt'],
                'min'   => $a['min'] === INF ? null : $a['min'],
                'max'   => $a['max'] === -INF ? null : $a['max'],
                default => null,
            };
            $groups[] = ['key' => $key, 'value' => $val, 'rows' => $a['cnt']];
        }
        usort($groups, fn($x, $y) => $y['rows'] <=> $x['rows']);

        // 5) 掃描量對比（本題核心考點）
        $colsScanned = max(1, count($usedColNames)); // count(*) 也至少掃 1 欄取列數
        $columnarCells = $rowCount * $colsScanned;     // 列式：只掃用到的欄
        $rowstoreCells = $rowCount * $totalCols;       // 行式：被迫讀整列所有欄
        $savingX = $columnarCells > 0
            ? round($rowstoreCells / $columnarCells, 2)
            : 0;

        return [
            'query' => [
                'select'  => $selectCol,
                'agg'     => $agg,
                'groupby' => $groupByCol,
            ],
            'groups' => $groups,
            'scan'   => [
                'row_count'          => $rowCount,
                'total_columns'      => $totalCols,
                'columns_used'       => $usedColNames,
                'columns_scanned'    => $colsScanned,
                'columnar_cells'     => $columnarCells,
                'rowstore_cells'     => $rowstoreCells,
                'rowstore_saving_x'  => $savingX, // 行式要多掃幾倍
            ],
        ];
    }
}
