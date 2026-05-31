# ChatGPT Tasks · PHP Demo

聚焦系統設計考點：**排程器（Scheduler）→ 延遲佇列（TaskQueue）→ 任務狀態機 + 冪等去重 / 失敗重試**。
不是 CRUD，而是「排定任務 → 到期入列 → worker 非同步執行 LLM」的完整非同步工作流。

## 啟動

```bash
cd demo
php -S localhost:8011 -t public
```

開瀏覽器 <http://localhost:8011>。

## 操作流程（核心 demo）

1. **建立任務**：一個「週期任務」（每 N 秒）+ 一個「一次性任務」（now + delay）。
2. **⏩ tick**：推進「邏輯時鐘」（模擬時間前進），scheduler 掃出 `next_run_at ≤ now` 的到期任務並入列（QUEUED）。
   - 週期任務入列後，自動排定下一次 `next_run_at`。
   - 同一觸發（`task_id@fire_at`）只會入列一次（**冪等去重**），重複 tick 不會重跑同一觸發。
3. **▶️ run worker**：取出所有 QUEUED 的 run，呼叫（假的）LLM 產生結果，狀態轉 `DONE`；
   勾選「模擬 LLM 失敗」的任務會走 `RETRY → QUEUED`，重試用盡後 `FAILED`。
4. 看 **任務清單**（排程狀態 / 下次執行）、**執行歷史**（狀態機 / 冪等鍵 / 嘗試次數）、**通知收件匣**。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/tasks` | 建立任務 `{ name, type, interval_sec\|delay_sec, prompt, force_fail }` |
| POST | `/api/tick` | 推進邏輯時間並掃出到期任務入列 `{ advance }` 或 `{ now }` |
| POST | `/api/run` | 執行 worker（消化 QUEUED 的 run） |
| POST | `/api/reset` | 清空所有資料 |
| GET | `/api/state` | JSON：目前任務 / runs / 通知 / 邏輯時間 |

## API 範例

```bash
# 建立週期任務（每 10 秒）
curl -X POST localhost:8011/api/tasks -d "name=每日新聞摘要&type=interval&interval_sec=10&prompt=摘要科技新聞"
# 前進 15 秒並掃描到期任務入列
curl -X POST localhost:8011/api/tick -d "advance=15"
# 執行 worker
curl -X POST localhost:8011/api/run
# 看狀態
curl localhost:8011/api/state
```

## 檔案

```
src/Scheduler.php   排程器：管理 next_run_at（一次性 + 週期），tick 掃出到期任務入列
src/TaskQueue.php   延遲佇列 + 狀態機（QUEUED→RUNNING→DONE/RETRY/FAILED）+ 冪等去重
src/Worker.php      佇列消費者：取出 run、呼叫（假）LLM、狀態轉移、重試、投遞通知
src/Store.php       三個 JSON 檔模擬 DB（tasks / runs / notifications）+ 檔案鎖發號器
public/index.php    路由 + 深色測試頁
data/               執行期產生（已 gitignore）
```

## 示意 vs 真實

| 元件 | 本 demo | 真實系統 |
|---|---|---|
| LLM 呼叫 | **假呼叫**（依 prompt 產生確定性文字，可模擬失敗） | OpenAI / Anthropic API |
| 排程掃描 | 單行程 `tick()` 掃 JSON | cron worker / 時間輪 / Redis ZSET / SQS 延遲投遞 |
| 佇列 | JSON 檔 + 檔案鎖 | SQS / RabbitMQ / Kafka |
| 狀態機 | JSON 紀錄 | DB 狀態表，冪等鍵建唯一索引 |
| 時鐘 | 手動推進的「邏輯時鐘」（方便示範時間前進） | 真實時間 + 使用者時區換算 |

**真實可執行邏輯**：排程的下次時間計算（含 catch-up）、延遲佇列入列、狀態機轉移、
冪等去重（`task_id@fire_at` 防同一觸發跑兩次）、失敗重試與死信。
**僅為示意**：LLM 內容、通知投遞通道、分散式鎖 / 租約（單行程 demo 省略）。
