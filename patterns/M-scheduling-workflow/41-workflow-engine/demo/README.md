# 工作流引擎 (Temporal/Airflow) · PHP Demo

聚焦系統設計考點：**DAG 排程（deps 全完成才 ready）→ durable execution / 事件溯源（狀態 = 重放 event log）→ 重試 + 指數退避 → saga 反向補償 → 人工審批等 signal → 重放不重跑**。

## 啟動

```bash
cd demo
php -S localhost:8041 -t public
```

開瀏覽器 <http://localhost:8041>。先「載入範例 DAG」，再按 **tick** 步進看 ready step 推進；可注入失敗看重試→saga 補償、對審批節點送 signal 放行、reset 後觀察重放不重跑。

## 範例 DAG（訂單履約）

```
reserve(預留庫存) ─┐
                    ├─▶ approve(風控審批) ─▶ pay(扣款) ─▶ ship(出貨) ─▶ notify(通知)
charge_prep(付款單)─┘
```

- `approve` 是 **審批節點**：tick 到它會 `BLOCKED`，等外部 `signal` 才繼續。
- 每個 task 都有 **補償動作**（如 `pay`→退款、`reserve`→釋放庫存）；某 step 最終失敗時，依**反向順序**補償已完成的 step。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/workflow` | 定義工作流 `{ steps:[...] }`；或 `{ "sample": true }` 載入內建範例 DAG |
| POST | `/api/run` | **tick**：執行一個排程步（挑 ready step 推進） |
| POST | `/api/signal` | 審批放行 `{ step }` |
| POST | `/api/fail` | 注入失敗 `{ step }`（示範重試 + saga 補償） |
| GET | `/api/state` | 目前狀態（DAG、各 step 狀態、ready 清單、event log、`replayedNoRerun`） |
| POST | `/api/reset` | 清空 event log（保留定義）→ 示範「重放不重跑」 |

## API 範例

```bash
# 1) 載入範例 DAG
curl -X POST localhost:8041/api/workflow -H "Content-Type: application/json" -d '{"sample":true}'

# 2) 步進：tick 一次完成 reserve + charge_prep；再 tick 到 approve 會 BLOCKED
curl -X POST localhost:8041/api/run
curl -X POST localhost:8041/api/run

# 3) 審批放行
curl -X POST localhost:8041/api/signal -H "Content-Type: application/json" -d '{"step":"approve"}'

# 4) 繼續 tick → pay → ship → notify → COMPLETED
curl -X POST localhost:8041/api/run

# 注入失敗看重試 + saga 補償：注入 ship，tick 到 ship 會重試到上限再 FAILED，
# 然後反向補償 pay → approve → charge_prep → reserve，流程 FAILED
curl -X POST localhost:8041/api/workflow -H "Content-Type: application/json" -d '{"sample":true}'
curl -X POST localhost:8041/api/fail -H "Content-Type: application/json" -d '{"step":"ship"}'
# ... tick 數次直到 saga

# 重放不重跑：看 state 的 replayedNoRerun（重放 event log 直接拿結果、未重跑的 step 數）
curl localhost:8041/api/state
```

## 檔案

```
src/WorkflowEngine.php  核心：define/loadSample(DAG 驗證,無環) /
                        tick(挑 ready step 推進) / replay(事件溯源重建狀態,不重跑) /
                        重試 backoff / runSaga(反向補償) / signal(審批放行) / injectFail / resetRun
public/index.php        路由 + 測試頁（步進 UI + DAG 狀態表 + event log）
data/                   執行期產生（已 gitignore）：
                          workflow.json     工作流定義（DAG）
                          events.ndjson     event log（事件溯源，append-only）
                          inject_fail.json  被注入失敗的 step 清單
```

## ⚠️ 誠實聲明：這是單機教學版

用「一個工作流實例 + 一份 event log + 手動 tick 步進」模擬 Temporal / Airflow 的核心，**沒有**分散式叢集、多 worker、task queue、心跳、計時器服務、可水平擴展的歷史服務。

但**DAG 排程與相依推進、durable execution / 事件溯源重放（含崩潰可續跑、重放不重跑 → exactly-once 效果）、重試 + 指數退避、saga 反向補償、人工審批等待外部 signal、確定性結果**等核心邏輯**皆為真實可執行**——這些正是面試考點。所有寫入用 `flock` 保護，支援多請求併發。
