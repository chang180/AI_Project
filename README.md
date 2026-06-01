# 系統設計面試 · 60 題實戰（fork）

> **本 repository 為 fork，非原創專案。** 題庫內容、筆記、架構圖與 PHP demo 皆來自上游 [**ryus2002/AI_Project**](https://github.com/ryus2002/AI_Project)。若覺得有用，請到上游按 ⭐ 支持原作者。

| | 連結 |
|---|---|
| **上游（原著）** | https://github.com/ryus2002/AI_Project |
| **本 fork** | https://github.com/chang180/AI_Project |
| **GitHub Pages（本 fork）** | https://chang180.github.io/AI_Project/ |

本 fork 額外做了：GitHub Pages 靜態佈署、站內連結修正、`ROADMAP.html` 等瀏覽體驗調整；**不包含對題庫內容的宣稱或再授權。**

---

依**共通模式**分 13 組，每題包含：HTML 筆記（9 段式）+ 內嵌 SVG / Excalidraw 架構圖 + PHP demo。全繁體中文。

> 入口：用瀏覽器開啟 [`index.html`](index.html)，或線上 https://chang180.github.io/AI_Project/

## 分組總覽

| 組 | 模式主題 | 題目 |
|---|---|---|
| **A** 通知 / Fan-out | 事件觸發 → 大規模可靠投遞、queue、retry | ②地震預警 ④價格追蹤 ⑧Webhook |
| **B** 即時連線 / 同步 | 長連線、低延遲、狀態同步（CRDT/OT） | ⑤RoboTaxi ⑦Messenger ⑨Google Docs |
| **C** 一致性 / 交易 | 強一致、防超賣/雙花、並發控制 | ③Polymarket ⑫Bookings |
| **D** 串流計算 / 儲存分發 | 大流量、聚合計算、儲存與 CDN | ①QR Code ⑥Spotify趨勢 ⑩YouTube |
| **E** AI / LLM 系統 | LLM、batching、async workflow、GPU | ⑪ChatGPT Tasks ⑬Agoda RAG ⑭LLM推論 |

## 目錄結構

```
patterns/
├── A-notification-fanout/   ② ④ ⑧
├── B-realtime-sync/         ⑤ ⑦ ⑨
├── C-consistency-txn/       ③ ⑫
├── D-stream-storage/        ① ⑥ ⑩
└── E-ai-llm/                ⑪ ⑬ ⑭
```

每題資料夾：`notes.html`（筆記）、`diagram.excalidraw`（架構圖，可上傳 https://excalidraw.com 開啟）、`demo/`（PHP）。

## 每題筆記 9 段式範本

1. 需求（功能 / 非功能）　2. 規模估算　3. API 設計　4. 資料模型
5. 高階架構（對應圖）　6. 核心元件深入　7. 擴展與瓶頸　8. 取捨與面試重點　9. PHP demo 說明

## 進度

- [x] ① QR Code Generator（範本）
- [ ] ② ④ ⑧　A 組
- [ ] ⑤ ⑦ ⑨　B 組
- [ ] ③ ⑫　C 組
- [ ] ⑥ ⑩　D 組剩餘
- [ ] ⑪ ⑬ ⑭　E 組

## 環境

- PHP 8.4（demo 用，`php -S localhost:8000` 啟動內建伺服器）
- 純前端筆記，瀏覽器直接開 `.html` 即可

## 取得原始碼

```bash
# 上游（原著，建議 star / 追蹤）
git clone https://github.com/ryus2002/AI_Project.git

# 本 fork
git clone https://github.com/chang180/AI_Project.git
```
