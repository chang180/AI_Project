# 網頁搜尋引擎（全鏈）· PHP Demo

聚焦系統設計考點：**分片倒排索引 → scatter-gather 查詢 → 連結圖 PageRank → BM25×PageRank 融合排序 → 查詢快取**。

這是第 ㉖ 題「全文搜尋（BM25）」的**全鏈強化版**：㉖ 是單一索引 + BM25，本題加上「分片」與「PageRank 權威分數」。

## 啟動

```bash
cd demo
php -S localhost:8038 -t public
```

開瀏覽器 <http://localhost:8038>。首次啟動會自動塞數篇**互相連結**的範例文件並算好 PageRank。

## 怎麼玩

1. 看「① 文件與連結圖」：每篇文件連到哪些 docId、落在哪個 shard、PageRank 分數。
2. 看「② 分片分布」：6+ 篇文件依 `crc32(docId) % 4` 散在 4 個 shard。
3. 看「③ 融合排序」：同一查詢，左邊純 BM25、右邊 BM25+PageRank 各半，**權威頁名次上升**。
4. 在「④ 自己查詢」調 `alpha`（相關性權重）/ `beta`（權威權重）、`op`（and/or），送出看 JSON。相同 (查詢, α, β) 第二次送出 → `cached:true`。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/doc` | 加文件 `{ title, body, links:[docId...] }` → 回 `{id, shard}` |
| POST | `/api/pagerank` | 在連結圖上算 PageRank `{ damping? }` |
| GET | `/api/search` | `?q=&op=&alpha=&beta=`：scatter-gather + 融合排序 |
| GET | `/api/state` | 分片分布、PageRank、文件、快取統計 |

## API 範例

```bash
# 加一篇連到 docId 101、102 的文件
curl -X POST localhost:8038/api/doc -H 'Content-Type: application/json' \
  -d '{"title":"Inverted Index","body":"inverted index search engine ranking","links":[101,102]}'

# 重算 PageRank
curl -X POST localhost:8038/api/pagerank -d '{"damping":0.85}'

# 純相關性 vs 融合：看排序差異
curl "localhost:8038/api/search?q=search+engine+ranking&alpha=1&beta=0"
curl "localhost:8038/api/search?q=search+engine+ranking&alpha=0.5&beta=0.5"
```

回傳 JSON 重點欄位：`perShardCandidates`（看 scatter 從各 shard 取到幾筆）、
`results[].bm25/pagerank/score`（看融合）、`cached`（看查詢快取命中）。

## 檔案

```
src/PageRank.php      連結圖冪次迭代算 PageRank（damping 0.85、處理 dangling node）
src/ShardedIndex.php  分片倒排索引 + scatter-gather + BM25 + 融合排序 + 查詢快取
public/index.php      路由 + 測試頁（含範例連結圖與融合排序對照）
data/                 執行期產生（已 gitignore）
```

## ⚠️ 誠實聲明

單機用迴圈**模擬**多個 shard（真實系統 shard 分散在不同機器、平行查詢、全域 df/avgdl 需分散維護）。
但**分片、scatter-gather、BM25 評分、PageRank 冪次迭代、BM25×PageRank 融合排序、查詢快取**
都是真實可執行的邏輯，只是規模小。零依賴：僅用 PHP 內建 `json` + `hash`，切詞用 ASCII `strtolower` + `preg_split`。
