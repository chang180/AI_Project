# Webhook 平台 · PHP Demo

聚焦系統設計考點：**HMAC 簽章 → fan-out 入列 → 投遞狀態機 → 指數退避重試 → DLQ**。
不是端點 CRUD，而是「對不可靠外部端點的可靠投遞」。

## 啟動

```bash
cd demo
php -S localhost:8008 -t public
```

開瀏覽器 <http://localhost:8008>：

1. 註冊一個 `mode: success` 端點、一個 `mode: fail` 端點。
2. 發布事件 `payment.succeeded` → 自動 fan-out 成多筆投遞任務（PENDING）。
3. 反覆按「▶ 觸發一輪投遞」→ 觀察失敗端點走 **指數退避 + 抖動** 重試，達上限後進 **DLQ（DEAD）** 並自動停用端點；DEAD 任務可手動「重送」。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/endpoints` | 註冊端點 `{ url, events, mode }` → 回 secret |
| POST | `/api/events` | 發布事件 `{ type, data }` → fan-out 入列 |
| POST | `/api/dispatch` | 觸發一輪投遞（模擬 worker） |
| POST | `/api/deliveries/{id}/redeliver` | 重送 DLQ 中的投遞 |
| GET | `/api/deliveries` | 投遞任務列表（JSON） |
| GET | `/api/dlq` | DLQ 列表（JSON） |
| POST | `/receive` | 內建測試接收端：驗 HMAC 後回 200 |

## API 範例

```bash
# 註冊「會失敗」的端點
curl -X POST localhost:8008/api/endpoints \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://acme.com/hook","events":["payment.succeeded"],"mode":"fail"}'

# 發布事件 → fan-out 入列
curl -X POST localhost:8008/api/events \
  -H 'Content-Type: application/json' \
  -d '{"type":"payment.succeeded","data":{"order":1234}}'

# 觸發投遞（多按幾次看退避與進 DLQ）
curl -X POST localhost:8008/api/dispatch
curl localhost:8008/api/dlq
```

驗章示範（內建接收端，secret 由查詢字串傳入僅供 demo）：

```bash
# 取得某端點的 secret 後，平台投遞時帶 X-Webhook-Timestamp / X-Webhook-Signature；
# /receive 會用 Signer::verify 核對時間視窗 + HMAC（hash_equals 常數時間比較）
```

## 檔案

```
src/EndpointStore.php   註冊端點(url + secret + mode)，JSON 檔模擬 DB
src/Signer.php          HMAC-SHA256 簽章/驗章，timestamp 防重放 + hash_equals 防時序攻擊
src/DeliveryQueue.php   投遞任務狀態機(PENDING/DELIVERING/DELIVERED/RETRY/DEAD) + DLQ，JSON 持久化
src/Dispatcher.php      投遞引擎：簽章→投遞→指數退避+抖動重試→超上限進 DLQ
public/index.php        路由 + 深色測試頁 + 內建 /receive 驗簽接收端
data/                   執行期產生（已 gitignore）
```

## ⚠️ 示意 vs 真實

- **真實邏輯**：HMAC 簽章與驗證、timestamp 防重放、投遞狀態機、指數退避 + 抖動、重試上限、DLQ、自動停用端點、fan-out（一事件 → N 投遞）、event_id 作冪等鍵。
- **示意處**：為避免 PHP 內建伺服器（單執行緒）在同進程內對自己發 HTTP 造成**自鎖**，預設用「**模擬投遞結果**」——依端點 `mode`（success/fail/slow）直接判定回應碼，不真的發外部 HTTP。即便如此，每次投遞仍會**真的算出簽章並驗一次**，證明簽/驗鏈路正確。
- 真實系統會用**獨立的 worker 程序池**並發、非阻塞地真正發 HTTP，並以**延遲佇列**承載 `next_retry_at`（`Dispatcher::deliverViaHttp()` 保留了真正發 HTTP 的範例）。
