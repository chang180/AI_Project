# 廣告點擊聚合 / 計費 · PHP Demo

聚焦系統設計考點：**點擊事件攝入 → click_id 冪等去重（exactly-once 計費）→ 同 IP 洪水欺詐過濾 → 每分鐘滾動視窗聚合**。不是 CRUD。

## 啟動

```bash
cd demo
php -S localhost:8022 -t public
```

開瀏覽器 <http://localhost:8022>，點「跑範例批次」一鍵觀察漏斗：**原始事件數 vs 去重後 vs 可計費數**。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 深色測試頁（一鍵示範） |
| POST | `/api/demo` | 跑內建範例批次（含重複 click_id + 某 IP 洪水），回漏斗 + 報表 |
| POST | `/api/ingest` | 餵入自訂事件批次 `{ events:[{click_id,ad_id,ip,ts},...] }` |
| GET | `/api/report?ad_id=ad_1` | 查某廣告分視窗計數與總計費 |
| GET | `/api/report` | 所有廣告聚合明細 + 全域漏斗統計 |
| POST | `/api/reset` | 清空去重集合 / 風控歷史 / 聚合視窗 |

## API 範例

```bash
# 一鍵範例（最直觀）
curl -X POST localhost:8022/api/demo

# 自訂批次：兩筆同 click_id（第二筆會被去重剔除）
curl -X POST localhost:8022/api/ingest -d '{"events":[
  {"click_id":"x1","ad_id":"ad_9","ip":"8.8.8.8","ts":1717200000},
  {"click_id":"x1","ad_id":"ad_9","ip":"8.8.8.8","ts":1717200000}
]}'

# 查某廣告分視窗計費
curl "localhost:8022/api/report?ad_id=ad_1"
```

## 檔案

```
src/ClickIngest.php   依 click_id 的 seen-set 冪等去重（重複丟棄，exactly-once 計費）
src/FraudFilter.php   同 IP 在短滑動視窗內超過門檻判洪水 → 剔除不計費
src/Aggregator.php    event-time tumbling window（每 60 秒）每 ad_id 累計可計費點擊
public/index.php      管線路由（去重→風控→聚合）+ 深色測試頁
data/                 執行期產生（已 gitignore）
```

## 範例批次預期結果（`POST /api/demo` 實跑輸出）

- 原始事件 `raw` = **24**（9 正常 + 3 重複投遞 + 12 洪水）
- 去重剔除 `dropped_duplicate` = **3**（c1/c2/c3 重送，被 click_id 冪等去重）
- 欺詐剔除 `dropped_fraud` = **5**（`1.2.3.4` 對 `ad_1` 在 12 秒內連點 12 次；風控用 10 秒滑動視窗、門檻 5，故跨視窗滑動下攔下 5 筆）
- 可計費 `billable` = **16**（其中 `ad_1` 視窗計數 10 = 3 正常 + 7 通過風控的點擊）

> 重點不在數字背誦，而在看到 **at-least-once 投遞造成的重複 → 被 click_id 去重收斂為 exactly-once，洪水流量被同 IP 滑動視窗風控攔下，最後只有乾淨點擊進入每分鐘分視窗計費**。改 `FraudFilter` 的 `windowSec` / `threshold` 即可看到攔截數隨之變化。

## ⚠️ 示意 vs 真實

| 元件 | 本 demo | 真實系統 |
|---|---|---|
| 事件管線 | 單機單行程、JSON 檔保存狀態 | Kafka（at-least-once）+ Flink keyed-state |
| 去重 seen-set | 記憶體 / JSON 持久化 | Redis / RocksDB，並設 TTL（依視窗大小） |
| 欺詐過濾 | 同 IP 速率門檻一層 | 多層：黑名單、裝置指紋、ML 評分、轉換分布 |
| 視窗聚合 | event-time tumbling window | Flink window + watermark 處理遲到/亂序 |
| 結果儲存 | 記憶體報表 | OLAP（ClickHouse / Druid）供計費對帳與報表 |

**去重的冪等鍵、同 IP 滑動視窗洪水偵測、event-time 歸桶聚合演算法皆為真實可執行**；分散式基建以單機示意。
