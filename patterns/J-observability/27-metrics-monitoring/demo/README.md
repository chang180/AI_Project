# 指標監控系統 Prometheus · PHP Demo

聚焦系統設計考點：**series 識別（name+labels）→ scrape 拉取 → 樣本追加 → rate()/sum by() 查詢 → 告警狀態機**。

## 啟動

```bash
cd demo
php -S localhost:8027 -t public
```

開瀏覽器 <http://localhost:8027>，用上方按鈕「Scrape 寫入」幾次（counter 值請逐次調大以形成時間窗），再跑 rate/sum 查詢與看告警。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（scrape / 看 series / 查詢 / 告警） |
| POST | `/api/scrape` | 餵曝露格式文字，或 `application/json` 的 `{samples:[...]}` |
| GET | `/metrics` | Prometheus 曝露格式輸出（本系統 series 最新值） |
| GET | `/api/query?expr=` | `instant` / `rate NAME[sec]` / `sum NAME [by(label)]` |
| GET | `/api/alerts` | 評估規則後回傳告警狀態機快照 |

## API 範例

```bash
# scrape 一批曝露格式樣本（counter 第一次）
curl -X POST localhost:8027/api/scrape --data-binary $'# TYPE http_requests_total counter\nhttp_requests_total{method="GET",code="200"} 1000'

# 稍後再 scrape 一次更大的值，形成時間窗
curl -X POST localhost:8027/api/scrape --data-binary $'# TYPE http_requests_total counter\nhttp_requests_total{method="GET",code="200"} 2000'

# 每秒變化率（含 counter reset 處理）
curl "localhost:8027/api/query?expr=rate%20http_requests_total%5B60%5D"

# 依 label 分組加總
curl "localhost:8027/api/query?expr=sum%20http_requests_total%20by(method)"

# 告警狀態
curl localhost:8027/api/alerts
```

## 檔案

```
src/Tsdb.php        時序資料庫：series 識別 / scrape 解析曝露格式 / append 樣本 /
                    instant / rate（counter reset）/ sum by / 告警狀態機（檔案鎖持久化）
public/index.php    路由 + 查詢解析 + 測試頁
data/               執行期產生（已 gitignore）：tsdb.json、alerts.json
```

## 核心概念對照

| Demo 做的（真實邏輯） | 真實 Prometheus |
|---|---|
| name+排序 labels 序列化成 series key | 倒排索引 + label set 唯一識別 series |
| scrape 文字解析，TSDB 打時間戳 | pull 模型，定時抓 target `/metrics` |
| (ts, value) append 到 JSON | WAL + block 壓縮、delta-of-delta 編碼 |
| rate() 取窗內 delta/dt，補 reset | PromQL rate/increase + extrapolation |
| sum by(label) | PromQL aggregation operators |
| pending→firing→resolved 狀態機 | recording/alerting rules + Alertmanager |

## ⚠️ 誠實聲明

單機 **JSON 檔模擬 TSDB**，多請求用 `flock` 保證安全。**series 識別、scrape 解析、樣本追加、instant/rate（含 counter reset）/sum 查詢、告警狀態機**皆為真實可執行邏輯；**未做**樣本壓縮、高基數（cardinality）最佳化、保留期回收、遠端儲存與聯邦。要接真 Prometheus，把本系統 `/metrics` 當作一個 exporter target 設進 `prometheus.yml` 的 `scrape_configs` 即可。
