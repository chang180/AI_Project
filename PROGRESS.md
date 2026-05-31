# 進度交接 PROGRESS

> 換電腦後先讀這份。所有進度都在 git，`git clone`/`git pull` 即可接續。

## 專案是什麼
14 題系統設計學習專案。每題產出：`notes.html`（9 段式筆記）+ `diagram.excalidraw`（架構圖）+ `demo/`（PHP，`php -S` 可跑）。全繁體中文。

- 入口：瀏覽器開 `index.html`
- 框架小抄：`framework.html`（每次練習前看）
- 技術決策：**原生 PHP 為主**（不用 Laravel），需要時才按題引入單一 Composer 套件。理由見對話/README。
- 環境：PHP 8.4（winget 裝），無 gh CLI。

## 分組（按共通模式）
- A 通知/Fan-out：② 地震 ④ 價格追蹤 ⑧ Webhook
- B 即時連線：⑤ RoboTaxi ⑦ Messenger ⑨ Google Docs
- C 一致性/交易：③ Polymarket ⑫ Bookings
- D 串流/儲存分發：**① QR Code（✅ 已完成範本）** ⑥ Spotify ⑩ YouTube
- E AI/LLM：⑪ ChatGPT Tasks ⑬ Agoda RAG ⑭ LLM 推論

## 已完成
- [x] 專案骨架、index.html、assets/style.css、5 組 README
- [x] framework.html 系統設計框架小抄
- [x] ① QR Code Generator 完整範本（notes + excalidraw + 可跑的 PHP demo）

## 待辦
- [ ] 其餘 13 題，沿用 ① QR Code 的檔案結構與版型
- [ ] （建議下一個）D 組的 ⑥ Spotify 趨勢榜、⑩ YouTube

## 模擬面試進度（① QR Code，當大師教學用）
進行到一半，暫停點如下，下次可從這裡接續：

- ✅ 已釐清：動態 QR、可改目標、要追蹤掃描
- ✅ 已估算：建立 ~115 寫 QPS、掃描 ~11,500 讀 QPS → **讀寫比 ~100:1（命脈：讀多寫少，重心在讀路徑快取）**
- ✅ 已定資料模型：`qrcodes`（對映表，要快取）+ `scan_events`（高頻寫，走 Kafka 非同步聚合）
- ✅ 已答出：掃描事件不進主庫（鎖競爭/關鍵路徑解耦/儲存膨脹）
- ⏸️ **下次續做：高階架構圖**——用文字填出寫路徑與讀路徑的箱子+箭頭，再把口述變成 diagram.excalidraw

**候選人(使用者)需要加強的點**：容易用「CRUD/前端表單」角度思考（一直想做使用者資料輸入頁），要練習用「核心實體 + 存取模式 + 為什麼這樣擴展」的系統設計鏡頭。
