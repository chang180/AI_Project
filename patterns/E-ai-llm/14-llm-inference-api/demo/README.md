# LLM 推論 API · PHP Demo

聚焦系統設計真正考點：**continuous batching（連續批次）如何動態填補 GPU 槽位**，而非 CRUD。

提交數個不同長度的請求 → 一步步推進排程器 → 親眼看到完成的請求**讓出槽位**、佇列中的新請求**當場填補**進來，並對比「連續批次 vs 靜態批次」的 GPU 槽利用率。

## 啟動

```bash
cd demo
php -S localhost:8014 -t public
```

開瀏覽器 <http://localhost:8014>：
1. 設定最大批次大小（GPU 槽位數，如 4）→ 重置排程器
2. 提交數個請求（或按「快速塞 6 個範例請求」，長度各異）
3. 「推進一個 step」逐步觀察，或「一路跑到完成」
4. 看每 step 哪些槽被填補、完成的請求空出槽位被新請求填入，以及利用率對比

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 深色測試頁 |
| POST | `/api/submit` | 提交請求 `{ tenant, priority, prompt, max_tokens }` |
| POST | `/api/step` | 推進排程器一個 step，回傳本 step 快照 |
| POST | `/api/run` | 一路推進到全部完成，回傳所有 step 歷史 |
| POST | `/api/reset` | 重置排程器（可帶 `max_batch`） |
| GET | `/api/state` | 目前統計 + 歷史 step 快照 |

## API 範例

```bash
curl -X POST localhost:8014/api/reset  -d '{"max_batch":4}'
curl -X POST localhost:8014/api/submit -d '{"tenant":"acme","priority":0,"prompt":"short","max_tokens":64}'
curl -X POST localhost:8014/api/submit -d '{"tenant":"free","priority":1,"prompt":"tell me a long story please","max_tokens":64}'
curl -X POST localhost:8014/api/step                 # 逐步推進
curl -X POST localhost:8014/api/run                  # 一次跑完
curl localhost:8014/api/state                        # 看利用率對比
```

## 檔案

```
src/InferenceModel.php   假「模型」：依 prompt 雜湊決定輸出長度，逐 token 產出（無真 GPU/權重）
src/Request.php          請求生命週期狀態機 QUEUED→PREFILL→DECODING→DONE，記 token 數/等待/完成 step
src/BatchScheduler.php   continuous batching 核心：每 step 先填補空槽再推進，統計槽利用率（連續 vs 靜態對照）
public/index.php         路由 + 深色測試頁
data/                    執行期產生的排程器狀態（已 gitignore，請勿手動建立）
```

## 核心邏輯：continuous batching

`BatchScheduler::step()` 每呼叫一次：

1. **填補**：只要批次有空槽且佇列（依優先級排序）非空，**立刻**拉新請求進場做 prefill —— 不等整批做完，這就是連續批次的精髓。
2. **推進**：為批次內每個 `DECODING` 請求生成 1 個 token；達到計畫長度者標記 `DONE` 並**當場讓出槽位**。
3. **統計**：累計每 step 被佔用的槽位數，算出平均 GPU 槽利用率；`staticBaselineUtilisation()` 以「每組等最長請求」計算靜態批次的理論利用率作對照。

> 因 PHP 內建伺服器每個請求是獨立 process，排程器無法跨請求常駐，故 `data/scheduler.json` 只存「請求清單 + 已推進 step 數」，每次 API 呼叫重建排程器並重放到該 step。排程語意完全一致。

## ⚠️ 示意 vs 真實

- **示意**：沒有真正的 GPU、模型權重或 token 生成；token 內容由 prompt 雜湊決定性產出，僅為占位字串。輸出長度也由雜湊決定，模擬「不同請求長度不一」。
- **真實**：continuous batching 的排程語意、槽位讓出與填補、優先級佇列、prefill→decode 狀態轉換、GPU 槽利用率統計（連續 vs 靜態對照）皆為真實可執行邏輯——這正是本題（vLLM / TGI）的核心考點。
- 要換成真模型：把 `InferenceModel::nextToken()` 換成對真實推論引擎（如 vLLM）的一次 forward 呼叫即可，排程器邏輯不變。
