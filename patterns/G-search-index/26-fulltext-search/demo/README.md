# 全文搜尋引擎 Twitter Search · PHP Demo

聚焦系統設計考點：**分析器（tokenize）→ 倒排索引（term → postings）→ BM25 相關性排序 → 布林 AND/OR 查詢**。

## 啟動

```bash
cd demo
php -S localhost:8026 -t public
```

開瀏覽器 <http://localhost:8026>，在搜尋框輸入詞（例如 `inverted index`），選 `AND`/`OR`，看依 BM25 分數排序的結果；也可從下方表單加入新文件即時進索引。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（搜尋框 + AND/OR + 結果 + 加文件） |
| POST | `/api/doc` | 新增文件 `{ title, body }` → 回 docId |
| GET | `/api/search?q=&op=and\|or` | 搜尋，回 BM25 排序結果（docId + 分數 + 命中詞） |

## API 範例

```bash
# 新增文件
curl -X POST localhost:8026/api/doc -d "title=Hello&body=full text search with bm25"

# 搜尋（OR：含任一詞；AND：含全部詞）
curl "localhost:8026/api/search?q=inverted%20index&op=or"
curl "localhost:8026/api/search?q=bm25%20ranking&op=and"
```

## 檔案

```
src/InvertedIndex.php  tokenize（小寫+切詞+stopwords）+ 倒排索引 + BM25 排序 + 布林 AND/OR + JSON 持久化
public/index.php       路由 + 測試頁（搜尋 / 加文件）+ 預設範例文件
data/                  執行期產生的索引（index.json / docs.json / counter.txt，已 gitignore）
```

## 核心邏輯說明

- **tokenize**：`strtolower` + `preg_split('/[^a-z0-9]+/')` 切詞，可選去英文 stopwords（純 ASCII，不用 `mb_*`）。
- **倒排索引**：`term → (docId → tf)`；另存每篇 doc 詞數、全集平均長度 `avgdl`、文件數 `N`。
- **BM25**（`k1=1.2, b=0.75`）：
  - `idf = log((N - df + 0.5) / (df + 0.5) + 1)`
  - `score = idf * (tf*(k1+1)) / (tf + k1*(1 - b + b*dl/avgdl))`
  - 每個 query term 的分數相加，依總分排序。
- **布林查詢**：`AND` = 候選 docId 取交集（含全部 query term），`OR` = 取聯集；兩者都用 BM25 排序。

## ⚠️ 誠實聲明

這是**單機、記憶體 + JSON 檔**的教學索引：
- **真實可執行**：tokenize、倒排索引、BM25 評分、AND/OR 布林查詢、索引持久化。
- **未實作**：索引分片（shard）/ 副本、位置索引與片語查詢、stemming、查詢 scatter-gather、近即時 refresh、查詢快取。

要上正式環境，把倒排索引與評分換成 **Elasticsearch / Lucene** 即可，查詢與排序語意一致。
