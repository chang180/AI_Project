# OLAP / 列式儲存 · PHP Demo

聚焦系統設計考點：**列式儲存（每欄一連續陣列）→ 分析查詢只掃用到的欄 → 對比行式掃描量 → 欄壓縮（字典 / RLE / Delta）→ 向量化聚合**。

## 啟動

```bash
cd demo
php -S localhost:8054 -t public
```

開瀏覽器 <http://localhost:8054>，先「載入樣本」，再看範例查詢與掃描量對比。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（載入 → 跑聚合查詢看只掃需要欄 + 對比行式掃描量 + 壓縮效果） |
| POST | `/api/load` | 產生 N 筆樣本事件並以列式入庫 `{ rows }` |
| GET | `/api/query` | 聚合 `?select=amount&groupby=region&agg=sum` |
| GET | `/api/state` | 列數 / schema / 各欄壓縮統計 |

## API 範例

```bash
curl -X POST localhost:8054/api/load -d "rows=2000"
curl "localhost:8054/api/query?select=amount&groupby=region&agg=sum"
curl "localhost:8054/api/query?select=&groupby=region&agg=count"
curl localhost:8054/api/state
```

`/api/query` 回傳裡的 `scan` 區塊是核心：

```json
"scan": {
  "row_count": 2000,
  "total_columns": 4,
  "columns_used": ["region","amount"],
  "columns_scanned": 2,
  "columnar_cells": 4000,      // 列式：只掃 2 欄
  "rowstore_cells": 8000,      // 行式：被迫讀整列 4 欄
  "rowstore_saving_x": 2.0     // 行式要多掃 2 倍才得到同一答案
}
```

## 檔案

```
src/ColumnStore.php   列式儲存：每欄一個連續陣列；行轉列入庫；只抓需要的欄
src/Compressor.php    欄壓縮：字典（低基數字串）/ RLE（連續重複）/ Delta（單調時間戳）
src/QueryEngine.php   向量化聚合 + 掃描量對比（列式 vs 行式）
public/index.php      路由 + 測試頁
data/                 執行期產生（已 gitignore）
```

## ⚠️ 誠實聲明

這是**記憶體 + JSON 檔模擬**的列式儲存，方便教學與離線執行。
其中「**只掃用到的欄、欄壓縮率、向量化聚合、列式 vs 行式掃描量對比**」是**真實計算**。

真實系統（ClickHouse / BigQuery / Snowflake / Parquet）會再加上：磁碟上的列式檔格式、
LZ4/ZSTD 二次壓縮、分區與 min/max 跳塊（zone map）、MPP 多節點平行掃描、向量化執行引擎。
本 demo 的查詢邏輯與這些系統一致，只是把儲存層換成記憶體陣列。
