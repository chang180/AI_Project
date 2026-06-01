# 分散式排程器 / Cron · PHP Demo

聚焦系統設計考點：**到點觸發 → 防重複執行 claim（fencing + 租約）→ misfire 補跑 → 分片指派**。

因 PHP 無跨請求常駐計時器，這裡用「**手動 tick 推進邏輯時鐘**」做確定性模擬：你按 tick 把時鐘往前推，到點的 job 產生一個 fire，多個 worker 競爭 claim 同一個 fire，只有一個能成功執行。

## 啟動

```bash
cd demo
php -S localhost:8042 -t public
```

開瀏覽器 <http://localhost:8042>，加 job → tick 觸發 → 多 worker 認領 → 模擬當掉看 misfire。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/job` | 新增排程 `{ jobId, interval, misfirePolicy }` |
| POST | `/api/tick` | 推進邏輯時鐘 `{ steps }` → 回本次到點 fire |
| POST | `/api/worker` | worker 上線/下線 `{ workerId, online }` |
| POST | `/api/claim` | worker 認領一個觸發 `{ workerId, fireId? }` |
| POST | `/api/complete` | 完成執行 `{ workerId, fireId, fenceToken }` |
| GET | `/api/state` | 目前完整狀態 |
| POST | `/api/reset` | 清空重來 |

## 操作劇本（建議照順序玩）

1. **加 job**：`send-report`，每 3 tick 觸發，misfire = `fire-once`。
2. **上線兩個 worker**：`w1`、`w2`（觀察 job 被分片指派到其中一個）。
3. **tick +3**：時鐘到 3，job 到點 → 產生一筆 fire（`pending`）。
4. **防重複**：用 `w1` 認領該 fire 成功（拿到 fencing token），同一 fire 再用 `w2` 搶 → 回「已被認領／去重生效」。**每次觸發只跑一次**。
5. **misfire**：把指派的 worker 下線（模擬當掉），`tick +10` 讓它錯過多個週期 → `fire-once` 只補跑一次、`fire-all` 每個錯過週期都補、`skip` 直接跳過。
6. **租約接管**：worker 認領後當掉沒 complete，tick 過租約（5 tick）→ 別的 worker 可重新認領（換更大 token，舊 token 的殭屍寫入會被 fencing 擋掉）。

## API 範例

```bash
curl -X POST localhost:8042/api/job    -H 'Content-Type: application/json' -d '{"jobId":"send-report","interval":3,"misfirePolicy":"fire-once"}'
curl -X POST localhost:8042/api/worker -H 'Content-Type: application/json' -d '{"workerId":"w1","online":true}'
curl -X POST localhost:8042/api/tick   -H 'Content-Type: application/json' -d '{"steps":3}'
curl -X POST localhost:8042/api/claim  -H 'Content-Type: application/json' -d '{"workerId":"w1"}'
curl    localhost:8042/api/state
```

## 檔案

```
src/Scheduler.php   排程定義 + tick 推進邏輯時鐘 + claim(fencing+租約)防重複 + misfire 策略 + 分片
public/index.php    路由 + 測試頁
data/               執行期產生（已 gitignore）
```

## ⚠️ 誠實聲明

本 demo 為**單機 + 手動 tick** 模擬「多 worker 常駐排程」。
**到點觸發、防重複 claim（fencing token + 租約到期）、misfire 補跑（fire-once / skip / fire-all）、分片指派**皆為**真實可執行邏輯**。

真實系統的差異：worker 是常駐進程、自己依牆鐘掃描；協調狀態放共用儲存（DB / etcd / ZooKeeper）；用心跳偵測 worker 當掉；用 **leader 選舉**避免多個 scheduler 重複掃描同一批 job。核心去重與補跑模型一致。
