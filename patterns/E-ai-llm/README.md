# 🅴 AI / LLM 系統

## 共通模式
以 LLM 為核心，涉及**非同步工作流**、**GPU 資源排程**與**檢索增強（RAG）**。

## 可複用心法
- **非同步任務**：請求 → 排入佇列 → worker 處理 → 回呼/輪詢結果；長任務用 job id 追蹤狀態。
- **GPU 吞吐**：**continuous batching**（動態批次）、KV cache、量化；最大化 GPU 利用率而非單請求延遲。
- **RAG 管線**：文件切塊 → 向量嵌入 → 向量資料庫檢索 → 重排序 → 組裝 prompt → 生成。
- **工具呼叫 / Agent**：LLM 決策 → 呼叫工具（查詢、退款 API）→ 觀察 → 迭代；需護欄與審計。
- **成本 / 限流**：token 計費、per-tenant 配額、快取相同查詢。

## 本組題目
| # | 題目 | 該題獨特挑戰 |
|---|---|---|
| 11 | ChatGPT Tasks | 排程/週期任務、async workflow、狀態機 |
| 13 | Agoda AI 客服 | RAG 知識庫、Agent 工具呼叫、自動退款交易安全 |
| 14 | LLM 推論 API | continuous batching、GPU 排程、吞吐優化 |
| 43 | 推薦系統（召回 + 排序） | 兩階段架構、協同過濾、embedding ANN 召回、特徵排序、冷啟動 |
