# 日誌聚合 Log Aggregation（ELK）· PHP Demo

聚焦系統設計考點：**攝取管線（ingest）→ tokenize → 倒排索引（詞 → 哪幾筆 log）→ 查詢（關鍵字 AND + 等級過濾 + 時間範圍）**。

對應 ELK：Logstash（攝取處理）+ Elasticsearch（倒排索引）+ Kibana（查詢）。

## 啟動

```bash
cd demo
php -S localhost:8045 -t public
```

開瀏覽器 <http://localhost:8045>：先攝取幾筆 log（或用批次 JSON），再用「關鍵字 + 等級 + 時間範圍」查詢，看命中與「候選 id 數」如何被倒排索引縮小。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（餵 log / 查詢 / 看倒排索引） |
| POST | `/api/logs` | 批次攝取 `application/json` 的 `{logs:[{ts,level,service,message}]}`；或測試頁單筆表單 |
| GET | `/api/search?q=&level=&from=&to=` | 倒排索引查詢：關鍵字 AND + 等級過濾 + 時間範圍 [from,to]（毫秒） |

## API 範例

```bash
# 批次攝取
curl -X POST localhost:8045/api/logs -H "Content-Type: application/json" --data-binary '{
  "logs":[
    {"level":"info","service":"auth-service","message":"user login success uid=42"},
    {"level":"error","service":"auth-service","message":"login failed invalid password uid=99"},
    {"level":"warn","service":"api-gateway","message":"upstream timeout GET /api/orders"}
  ]
}'

# 關鍵字查詢（單詞）
curl "localhost:8045/api/search?q=failed"

# 多關鍵字 AND（取交集）+ 等級過濾
curl "localhost:8045/api/search?q=login%20failed&level=error"

# 加時間範圍（毫秒）
curl "localhost:8045/api/search?q=timeout&from=0&to=99999999999999"
```

## 檔案

```
src/LogIndex.php    倒排索引核心：ingest / tokenize / 倒排索引(詞→posting list) /
                    search（關鍵字 AND 交集 + 等級 + 時間範圍）+ 觀測輔助（flock 持久化）
public/index.php    路由 + 測試頁
data/               執行期產生（已 gitignore）：logs.json
```

## 核心概念對照

| Demo 做的（真實邏輯） | 真實 ELK |
|---|---|
| ingest 分配遞增 id、存結構化文件 | Logstash/Beats 攝取，Elasticsearch 寫入文件 |
| tokenize 訊息成小寫詞 | analyzer（tokenizer + filter）切詞 |
| 詞 → posting list（哪幾筆 log） | Lucene 倒排索引（term → postings） |
| 多詞 posting list 取交集（AND） | bool query must（postings 取交集） |
| 等級 + 時間範圍過濾 | term/range filter（structured fields） |
| 時間新到舊排序 | sort by `@timestamp` desc |

## ⚠️ 誠實聲明

單機 **JSON 檔模擬**，多請求用 `flock` 保證安全。**攝取管線、tokenize、倒排索引、關鍵字 AND 交集、等級 + 時間範圍過濾、排序**皆為真實可執行邏輯；**未做**索引分片、時間分桶 index rollover、保留分層（hot/warm/cold）、壓縮、相關性評分（TF-IDF/BM25）與分散式合併。tokenize 採 ASCII 規則（不依賴 `mb_*`），中文整段視為一個詞。要接真 ELK，把本系統的攝取端改成 Filebeat → Logstash → Elasticsearch 即可，倒排索引與查詢語意一致。
