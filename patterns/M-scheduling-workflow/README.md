# 🅼 排程 / 工作流

## 共通模式
「**讓事情在對的時間、可靠地、不重不漏地跑完**」的編排層：定時觸發（Cron）與多步驟流程編排（Workflow）。本身建立在分散式協調原語（鎖、選主、租約）之上，但重心是**可靠執行語意**——at-least-once + 冪等、崩潰可續跑、失敗可補償。

## 可複用心法
- **手動 tick 驅動時間**：教學模擬用邏輯時鐘（tick）取代真實計時器，讓觸發與重試行為可確定性重現。
- **防重複執行**：多 worker 搶同一觸發/步驟時，用鎖/claim + **fencing token** + 租約到期，確保「恰好一個 worker 執行一次」。
- **durable execution / 事件溯源**：每步結果寫 event log；崩潰後重放 log 重建狀態，**已完成的步驟不重跑**，達成 exactly-once 效果。
- **DAG 排程**：相依全完成的步驟才 ready；引擎挑 ready step 執行，天然支援並行與依賴。
- **重試 + 補償（saga）**：步驟失敗指數退避重試；最終失敗則依**反向順序**對已完成步驟執行補償動作。
- **misfire 策略**：worker 當掉錯過觸發 → 補跑一次 / 全補 / 跳過，依業務語意選擇。
- **分片與 leader**：job 依雜湊分片到多 worker；用 leader 選舉避免重複掃描。

## 本組題目
| # | 題目 | 該題獨特挑戰 |
|---|---|---|
| 41 | 工作流引擎（Temporal/Airflow） | DAG 排程、durable execution 事件溯源重放、重試 backoff、saga 補償、人工審批節點 |
| 42 | 分散式排程器 / Cron | 到點觸發、防重複執行（claim + fencing + 租約）、misfire 補跑、分片、at-least-once |
