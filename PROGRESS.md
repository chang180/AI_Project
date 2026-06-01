# 進度交接 PROGRESS

> 換電腦後先讀這份。所有進度都在 git，`git clone`/`git pull` 即可接續。

## 專案是什麼
60 題系統設計學習專案（原 14 題核心 + 46 題進階補充）。每題產出：`notes.html`（9 段式筆記，**內嵌 SVG 架構圖**）+ `diagram.excalidraw`（可編輯原稿）+ `diagram.svg`（自動生成）+ `demo/`（PHP，`php -S` 可跑）。全繁體中文。

> 架構圖：`tools/excalidraw-to-svg.php` 把 `diagram.excalidraw` 轉成 `diagram.svg`（永久、離線、可內嵌），notes 用 `<img class="diagram-svg">` inline 顯示。改完圖跑 `php tools/excalidraw-to-svg.php --all` 重新生成。

- 入口：瀏覽器開 `index.html`
- 框架小抄：`framework.html`（每次練習前看）
- 技術決策：**原生 PHP 為主**（不用 Laravel），需要時才按題引入單一 Composer 套件。理由見對話/README。
- 環境：PHP 8.4（winget 裝），無 gh CLI。**注意本機未載入 mbstring**，demo 已全部改用 UTF-8 安全的原生函式，零依賴可跑。

## 分組（按共通模式）
- A 通知/Fan-out：② 地震 ④ 價格追蹤 ⑧ Webhook
- B 即時連線：⑤ RoboTaxi ⑦ Messenger ⑨ Google Docs ㉚ 視訊會議 Zoom
- C 一致性/交易：③ Polymarket ⑫ Bookings ⑰ 數位錢包/支付 ㉘ 秒殺/搶票
- D 串流/儲存分發：① QR Code ⑥ Spotify ⑩ YouTube ⑳ Dropbox ㉑ 直播 ㉒ 廣告聚合 ㉙ 排行榜
- E AI/LLM：⑪ ChatGPT Tasks ⑬ Agoda RAG ⑭ LLM 推論 ㊸ 推薦系統
- F 社交/Feed：⑮ News Feed 55 TikTok 短影音
- G 搜尋/索引/地理空間：⑯ Typeahead ⑱ 附近的人 Yelp ㉖ 全文搜尋 52 向量檢索 58 Tinder
- H 基礎設施/平台元件：⑲ 分散式限流器 ㉓ Snowflake ID ㉕ Kafka ㊾ API Gateway ㊿ DNS 51 CDN
- I 認證/安全：㉔ OAuth/SSO
- J 可觀測性：㉗ Prometheus ㊹ 分散式追蹤 ㊺ 日誌系統 ELK
- K 分散式系統：㉛ KV store ㉜ LSM ㉝ S3 ㉞ 分散式快取 ㉟ Raft ㊱ 分散式鎖 ㊻ MySQL 分庫分表 ㊼ 分散式交易 NewSQL
- L 圖/大規模演算法：㊲ 網頁爬蟲 ㊳ 搜尋引擎 ㊴ 地圖路徑 ㊵ Splitwise
- M 排程/工作流：㊶ 工作流引擎 ㊷ 分散式排程器
- D 另含：㊽ CDC 資料管線 53 MapReduce 54 OLAP 列式 59 反詐騙風控
- B 另含：57 外送 DoorDash 60 E2EE 加密通訊；C 另含：56 電商訂單系統

## 已完成 ✅ 全部 60 題
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
| ⑮ | News Feed | 推/拉/hybrid fan-out + 名人問題 + 時間線合併 | 8015 |
| ⑯ | Typeahead | Trie + 每前綴 Top-K 預存 + 頻率更新 | 8016 |
| ⑰ | 數位錢包/支付 | 雙分錄帳本 + 冪等扣款 + 防透支 + 對帳守恆 | 8017 |
| ⑱ | 附近的人 Yelp | geohash 空間索引 + 鄰格邊界 + 半徑/最近K | 8018 |
| ⑲ | 分散式限流器 | 令牌桶 + 滑動視窗 + per-key 原子計數 | 8019 |
| ⑳ | Dropbox 同步 | 分塊去重 + 增量 delta + 衝突副本 | 8020 |
| ㉑ | 直播 Twitch | 低延遲 HLS 滑動視窗 manifest + 聊天 fan-out | 8021 |
| ㉒ | 廣告點擊聚合 | 去重 exactly-once + 防欺詐 + tumbling window | 8022 |
| ㉓ | 唯一 ID Snowflake | 64-bit 位元佈局 + 同毫秒序號遞增 + 時鐘回撥 + 雙向解碼 | 8023 |
| ㉔ | OAuth/SSO | 授權碼單次使用 + JWT(HS256) 簽發 + refresh 輪替 + 撤銷名單 | 8024 |
| ㉕ | Kafka 訊息佇列 | 依 key 分區(crc32) + 單調 offset + 消費者組 + 重放 + rebalance | 8025 |
| ㉖ | 全文搜尋 | 倒排索引(term→docId→tf) + BM25 排序 + AND/OR 布林查詢 | 8026 |
| ㉗ | Prometheus 監控 | series=name+labels + scrape + rate()(含 reset) + sum by + 告警狀態機 | 8027 |
| ㉘ | 秒殺/搶票 | 原子條件扣減防超賣(CAS) + 冪等鍵 + 入場令牌削峰 + 每人限購 | 8028 |
| ㉙ | 遊戲排行榜 | ZSET 語意 zadd/zrevrank/Top-K + 我的名次±鄰居 + 分窗榜 | 8029 |
| ㉚ | 視訊會議 Zoom | 信令中繼(SDP/ICE) + SFU 轉發表 + simulcast 依頻寬選層 + active speaker | 8030 |
| ㉛ | 分散式 KV store | 一致性雜湊+vnode + quorum N/R/W + 向量時鐘 sibling + read repair + hinted handoff | 8031 |
| ㉜ | LSM 儲存引擎 | WAL + memtable→SSTable flush + bloom 省 IO + compaction（vs B-tree） | 8032 |
| ㉝ | 物件儲存 S3 | multipart 組裝 + 一致性雜湊放置 + 副本/抹除碼(XOR)還原 + 預簽 URL | 8033 |
| ㉞ | 分散式快取 | 16384 slot 路由+重分片 + 主從 + LRU/LFU + 穿透/擊穿/雪崩緩解 | 8034 |
| ㉟ | Raft 共識 | leader election + log replication + 多數 commit + 掛 leader 重選不丟 log | 8035 |
| ㊱ | 分散式鎖+服務發現 | 租約鎖 + fencing token 擋過期寫 + watch + leader election + 服務註冊 | 8036 |
| ㊲ | 網頁爬蟲 | BFS frontier + bloom 去重 + politeness/crawl-delay + 依 domain 分片 + 重抓 | 8037 |
| ㊳ | 搜尋引擎全鏈 | 倒排索引分片 + scatter-gather + PageRank 冪次迭代 + BM25+PR 融合排序 | 8038 |
| ㊴ | 地圖路徑規劃 | 道路圖 + Dijkstra vs A*(探索更少) + 即時路況 ETA + 瓦片 | 8039 |
| ㊵ | Splitwise 分攤 | 三種分攤 + 淨額守恆(和=0) + 最小現金流債務簡化 + 整數分 + 冪等 | 8040 |
| ㊶ | 工作流引擎 | DAG 排程 + 事件溯源 durable execution(重放不重跑) + 重試 + saga 補償 + 審批 | 8041 |
| ㊷ | 分散式排程器 | 到點觸發 + claim+fencing+租約防重複執行 + misfire 補跑 + 分片 | 8042 |
| ㊸ | 推薦系統 | 兩階段：協同過濾+ANN 召回 → 特徵排序 Top-N + 冷啟動熱門 fallback | 8043 |
| ㊹ | 分散式追蹤 | 由扁平 span 重建呼叫樹 + 關鍵路徑找最慢(self-time) + head 取樣 | 8044 |
| ㊺ | 日誌系統 ELK | 攝取管線 + 倒排索引 + 關鍵字/等級/時間範圍查詢 | 8045 |
| ㊻ | MySQL 分庫分表 | 分片路由 + 跨片 scatter-gather + 分散式自增 + re-shard（vs NoSQL） | 8046 |
| ㊼ | 分散式交易 NewSQL | 2PC prepare/commit/abort + 跨片轉帳守恆回滾（vs Saga） | 8047 |
| ㊽ | CDC 資料管線 | 變更事件(op/before/after/LSN) + 下游有序冪等套用 + 最終一致 | 8048 |
| ㊾ | API Gateway/LB | 路由 + LB(RR/最少連線/一致性雜湊) + 健康檢查繞過 + 熔斷半開 | 8049 |
| ㊿ | DNS 系統 | 階層遞迴解析鏈 + 快取 TTL + CNAME 跟進 + GeoDNS 就近 | 8050 |
| 51 | CDN 內容分發 | 邊緣 hit/miss + 回源 TTL + purge 失效 + 一致性雜湊選邊緣 + 就近 | 8051 |
| 52 | 向量檢索 ANN | cosine + 暴力 kNN vs IVF 分群近似 + 召回率 vs 掃描數(nprobe) | 8052 |
| 53 | MapReduce | split→map→shuffle(hash%R 分區)→reduce + combiner 預聚合 | 8053 |
| 54 | OLAP 列式儲存 | 列式只掃需要欄(vs 行式) + 欄壓縮 + 向量化聚合 GROUP BY | 8054 |
| 55 | TikTok 短影音 | 多路召回→排序→去重 + 興趣回饋迴路 + 預載 + 冷啟動（組合 10/43/15） | 8055 |
| 56 | 電商訂單系統 | 訂單狀態機 + saga 反向補償 + 庫存原子預扣 + 冪等下單（組合 28/17/41） | 8056 |
| 57 | 外送 DoorDash | 三邊市場 + geohash 找最近外送員 + CAS 指派 + 訂單狀態機 + ETA（組合 5） | 8057 |
| 58 | Tinder 配對 | geohash 候選 + 雙向偏好過濾 + 互相 like 配對偵測 + 已看去重（組合 18/43） | 8058 |
| 59 | 反詐騙風控 | 滑動視窗速度特徵 + 規則加權評分 + 三分決策 allow/review/deny | 8059 |
| 60 | E2EE 加密通訊 | 玩具 DH 金鑰交換 + KDF + 雙棘輪前向保密 + 伺服器只見密文（教學示意） | 8060 |

啟動任一 demo：`cd <題目>/demo && php -S localhost:<埠> -t public`
（H/I/J 新題實際路徑：`patterns/H-infra-primitives/23-snowflake-id`、`patterns/I-auth-security/24-oauth-sso`、`patterns/H-infra-primitives/25-message-queue`、`patterns/G-search-index/26-fulltext-search`、`patterns/J-observability/27-metrics-monitoring`、`patterns/C-consistency-txn/28-flash-sale`、`patterns/D-stream-storage/29-leaderboard`、`patterns/B-realtime-sync/30-video-conferencing`）

> 8 題進階補充題的推薦理由與其餘未做題目清單，見對話紀錄（涵蓋搜尋/金流/地理/基建/社交/即時媒體/大數據等維度）。

## 待辦

### ✅ 下一批 8 題（23–30）已完成（2026-06-01）
全部 8 題已建好 notes.html + diagram.excalidraw + 可跑 demo，皆過 `php -l`、實跑 HTTP 200、diagram 合法 JSON。新增 2 分組 🅸 認證/安全、🅹 可觀測性。index.html（30/30、加 I/J 區塊與卡片）、各分組 README、本檔表格皆已更新。

### ✅ 擴充 13 題（31–43）已完成（2026-06-01）
依 [ROADMAP.md](ROADMAP.md) 建完。新增 3 分組：🅺 分散式系統（31–36，原規劃 K 資料儲存 + L 協調/共識**合併為一組**，因屬同類地基且使用者反映較難）、🅻 圖/大規模演算法（37–40）、🅼 排程/工作流（41–42），並延伸 🅴 推薦系統（43）。皆過 `php -l`、實跑 HTTP 200、diagram 合法 JSON。
同時導入 **SVG 內嵌**：`tools/excalidraw-to-svg.php` 生成 `diagram.svg`，全 43 題 notes 改用 `<img class="diagram-svg">` inline 顯示（不再只是連結，永久離線可看）。index.html（43/43、加 K/L/M 區塊與卡片）、各分組 README、本檔皆已更新。

### ✅ 擴充 17 題（44–60）已完成（2026-06-01）
依 [ROADMAP.md](ROADMAP.md) 的 Tier 3 深化方向 + 知名綜合題建完，全部歸進現有組（不另開新組）：
- 🅹 可觀測性補完：㊹ 分散式追蹤、㊺ 日誌系統 ELK
- 🅺 關聯式資料庫：㊻ MySQL 分庫分表、㊼ 分散式交易 NewSQL
- 🅷 流量入口層：㊾ API Gateway、㊿ DNS、51 CDN
- 🅳/🅶 大數據/AI 檢索：㊽ CDC、53 MapReduce、54 OLAP、52 向量檢索
- 🧩 綜合題：55 TikTok、56 電商訂單、57 DoorDash、58 Tinder、59 反詐騙、60 E2EE
皆過 `php -l`、實跑 HTTP 200、diagram 合法 JSON/SVG，含完整版 + 初學版(含「核心程式」章節)。index.html(60/60)、各分組 README、本檔、ROADMAP 皆已更新。**全專案 60/60。**

### 其餘可選（未來方向）
- [ ] 把各 demo 的「示意」元件換成真套件：QR→`endroid/qr-code`、RAG→真向量嵌入、LLM→真模型、E2EE→真 libsodium；系統邏輯不需改。
- [ ] 依 framework.html 自我計時重做每題、補充個人筆記。
- [ ] 更多維度（如 Spanner TrueTime 深入、服務網格、ML 特徵平台…）可再開新批。

## 模擬面試進度（① QR Code，當大師教學用）
進行到一半，暫停點如下，下次可從這裡接續：

- ✅ 已釐清：動態 QR、可改目標、要追蹤掃描
- ✅ 已估算：建立 ~115 寫 QPS、掃描 ~11,500 讀 QPS → **讀寫比 ~100:1（命脈：讀多寫少，重心在讀路徑快取）**
- ✅ 已定資料模型：`qrcodes`（對映表，要快取）+ `scan_events`（高頻寫，走 Kafka 非同步聚合）
- ✅ 已答出：掃描事件不進主庫（鎖競爭/關鍵路徑解耦/儲存膨脹）
- ⏸️ **下次續做：高階架構圖**——用文字填出寫路徑與讀路徑的箱子+箭頭，再把口述變成 diagram.excalidraw

**候選人(使用者)需要加強的點**：容易用「CRUD/前端表單」角度思考（一直想做使用者資料輸入頁），要練習用「核心實體 + 存取模式 + 為什麼這樣擴展」的系統設計鏡頭。
