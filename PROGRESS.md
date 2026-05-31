# 進度交接 PROGRESS

> 換電腦後先讀這份。所有進度都在 git，`git clone`/`git pull` 即可接續。

## 專案是什麼
14 題系統設計學習專案。每題產出：`notes.html`（9 段式筆記）+ `diagram.excalidraw`（架構圖）+ `demo/`（PHP，`php -S` 可跑）。全繁體中文。

- 入口：瀏覽器開 `index.html`
- 框架小抄：`framework.html`（每次練習前看）
- 技術決策：**原生 PHP 為主**（不用 Laravel），需要時才按題引入單一 Composer 套件。理由見對話/README。
- 環境：PHP 8.4（winget 裝），無 gh CLI。**注意本機未載入 mbstring**，demo 已全部改用 UTF-8 安全的原生函式，零依賴可跑。

## 分組（按共通模式）
- A 通知/Fan-out：② 地震 ④ 價格追蹤 ⑧ Webhook
- B 即時連線：⑤ RoboTaxi ⑦ Messenger ⑨ Google Docs
- C 一致性/交易：③ Polymarket ⑫ Bookings
- D 串流/儲存分發：① QR Code ⑥ Spotify ⑩ YouTube
- E AI/LLM：⑪ ChatGPT Tasks ⑬ Agoda RAG ⑭ LLM 推論

## 已完成 ✅ 全部 14 題
每題皆含 notes.html（9 段式）+ diagram.excalidraw + 可跑 PHP demo，並通過 `php -l` 與實跑驗證。各 demo 的核心邏輯重點：

| # | 題目 | demo 核心考點（非 CRUD） | 埠 |
|---|---|---|---|
| ① | QR Code | 短碼發號(KGS) + 快取 + 轉址 + 計數 | 8000 |
| ② | 地震預警 | haversine 地理篩選 + fan-out + 冪等退避重試 + DLQ | 8002 |
| ③ | Polymarket | order book 價格-時間優先撮合 + 凍結/過帳/防超額 | 8003 |
| ④ | 價格追蹤 | 條件匹配(反向索引) + 去抖狀態機 ARMED/TRIGGERED/COOLDOWN | 8004 |
| ⑤ | RoboTaxi | geohash 地理查詢 + CAS 原子媒合(防重複指派) | 8005 |
| ⑥ | Spotify 趨勢榜 | Count-Min Sketch 近似計數 + Top-K + 時間衰減 | 8006 |
| ⑦ | Messenger | 連線註冊表 + 路由投遞 + per-conv 序號/已讀/離線 | 8007 |
| ⑧ | Webhook | HMAC 簽章 + 指數退避重試 + DLQ + 端點隔離 | 8008 |
| ⑨ | Google Docs | OT transform 讓並發編輯收斂(含中文字元級) | 8009 |
| ⑩ | YouTube | 分塊上傳 + 轉碼管線狀態機 + HLS manifest | 8010 |
| ⑪ | ChatGPT Tasks | 排程 → 延遲佇列 → 狀態機 + 冪等/重試 | 8011 |
| ⑫ | Bookings | 區間原子扣減防超賣 + 悲觀/樂觀鎖 + hold/TTL | 8012 |
| ⑬ | Agoda RAG | RAG 檢索帶引用 + Agent 工具呼叫 + 退款護欄/冪等 | 8013 |
| ⑭ | LLM 推論 | continuous batching 動態填補 GPU 槽位 | 8014 |

啟動任一 demo：`cd <題目>/demo && php -S localhost:<埠> -t public`

## 待辦
- [ ] （可選）把各 demo 的「示意」元件換成真套件：QR 換 `endroid/qr-code`、RAG 換真向量嵌入、LLM 換真模型；系統邏輯不需改。
- [ ] （可選）依 framework.html 自我計時重做每題、補充個人筆記。

## 模擬面試進度（① QR Code，當大師教學用）
進行到一半，暫停點如下，下次可從這裡接續：

- ✅ 已釐清：動態 QR、可改目標、要追蹤掃描
- ✅ 已估算：建立 ~115 寫 QPS、掃描 ~11,500 讀 QPS → **讀寫比 ~100:1（命脈：讀多寫少，重心在讀路徑快取）**
- ✅ 已定資料模型：`qrcodes`（對映表，要快取）+ `scan_events`（高頻寫，走 Kafka 非同步聚合）
- ✅ 已答出：掃描事件不進主庫（鎖競爭/關鍵路徑解耦/儲存膨脹）
- ⏸️ **下次續做：高階架構圖**——用文字填出寫路徑與讀路徑的箱子+箭頭，再把口述變成 diagram.excalidraw

**候選人(使用者)需要加強的點**：容易用「CRUD/前端表單」角度思考（一直想做使用者資料輸入頁），要練習用「核心實體 + 存取模式 + 為什麼這樣擴展」的系統設計鏡頭。
