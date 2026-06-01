# 向量資料庫 / ANN 搜尋 · PHP Demo

聚焦系統設計考點：**相似度（cosine/內積）→ 暴力 kNN（基準）→ ANN 近似（IVF 倒排檔，只搜近群）→ 速度 vs 召回率取捨**。

## 啟動

```bash
cd demo
php -S localhost:8052 -t public
```

開瀏覽器 <http://localhost:8052>，新增向量、輸入查詢向量 q，按「兩者比較」看
**暴力掃 N 個 vs ANN 只掃 M 個** 的掃描數差異與召回率。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（加向量、kNN 查詢、暴力 vs ANN 比較） |
| POST | `/api/vectors` | 新增向量 `{ vec:[..], label }` |
| GET | `/api/search?q=&k=&mode=brute\|ann&nprobe=` | kNN 查詢 |
| GET | `/api/state` | 向量數 / 維度 / IVF 群數 |

## API 範例

```bash
# 暴力 kNN：掃全部 N 筆，當正確答案基準
curl "localhost:8052/api/search?q=0.9,0.1,0,0&k=3&mode=brute"

# ANN(IVF)：只比最近 nprobe 個群裡的向量 → 掃描數變少
curl "localhost:8052/api/search?q=0.9,0.1,0,0&k=3&mode=ann&nprobe=1"

# 新增一筆 4 維向量
curl -X POST localhost:8052/api/vectors -d "vec=0.8,0.15,0,0&label=梨子"
```

回應裡的 `scanned`（ANN 只掃 M）對比 `total_n`（暴力掃 N），以及 `recall`（ANN top-k 命中暴力答案的比例），就是本題的核心取捨。

## 檔案

```
src/Similarity.php    cosine / 內積相似度
src/VectorIndex.php   暴力 kNN（基準）+ IVF 倒排檔 ANN（簡化 k-means 分群、只搜近群）+ 召回率
src/VectorStore.php   JSON 檔模擬向量 DB（id + vec + metadata）+ 檔案鎖
public/index.php      路由 + 測試頁（暴力 vs ANN 比較）
data/                 執行期產生（已 gitignore）
```

## ⚠️ 誠實聲明

向量為 **小維度「假嵌入」**（手動指定的數字，非真實嵌入模型輸出）；
索引為**簡化 IVF**（每次查詢在記憶體重建、簡化 k-means、無量化壓縮）。
但 **相似度計算、暴力 vs ANN 掃描數、IVF 分群只搜近群、召回率取捨為真實可執行**。
要上正式環境，把這層換成 **Faiss / Milvus / pgvector / HNSW**，並接真實嵌入模型（如 sentence-transformers / OpenAI embeddings），查詢與評分語意一致。
