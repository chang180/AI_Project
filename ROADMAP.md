# 📈 題庫 Roadmap

> 現況：**43 / 43 題完成**（分組 A–M，見 [PROGRESS.md](PROGRESS.md) 與 [index.html](index.html)）。
> 本檔記錄已完成的擴充與「之後還值得補的題目」。

## ✅ 已完成的擴充（31–43，2026-06-01）
依優先序「地基 > 經典設計 ≈ 排程/工作流/推薦」建完 13 題，新增 3 分組 + 延伸 E。
**註：原規劃的 K（分散式資料儲存）+ L（協調/共識）已合併成單一「🅺 分散式系統」組**（同屬最難的地基題），後續組順延為 L/M。

### 🅺 分散式系統（K-distributed-systems）★地基
| # | 題目 | 核心考點 | 埠 |
|---|---|---|---|
| ㉛ | 分散式 KV 儲存 (DynamoDB/Cassandra) | 一致性雜湊+vnode、quorum N/R/W、向量時鐘、read repair、hinted handoff | 8031 |
| ㉜ | LSM-tree 儲存引擎 | WAL、memtable→SSTable、bloom、compaction、vs B-tree | 8032 |
| ㉝ | 物件儲存 S3 | multipart、一致性雜湊放置、副本/抹除碼、預簽 URL | 8033 |
| ㉞ | 分散式快取 (Redis Cluster) | 16384 slot 路由+重分片、主從、LRU/LFU、穿透/擊穿/雪崩 | 8034 |
| ㉟ | 共識演算法 Raft | leader election、log replication、commit、選舉限制、故障重選 | 8035 |
| ㊱ | 分散式鎖 + 服務發現 | 租約、fencing token、watch、leader election、服務註冊/設定 | 8036 |

### 🅻 圖 / 大規模演算法（L-graph-scale）
| # | 題目 | 核心考點 | 埠 |
|---|---|---|---|
| ㊲ | 網頁爬蟲 | BFS frontier、bloom 去重、politeness、分散式 frontier、重抓 | 8037 |
| ㊳ | 網頁搜尋引擎（全鏈） | 倒排索引分片+scatter-gather、PageRank、BM25+PR 融合排序、快取 | 8038 |
| ㊴ | 地圖路徑規劃 (Google Maps) | Dijkstra/A*、contraction hierarchies、瓦片、即時路況 ETA | 8039 |
| ㊵ | Splitwise 帳務分攤 | 三種分攤、淨額守恆、最小現金流債務簡化、整數分、冪等 | 8040 |

### 🅼 排程 / 工作流（M-scheduling-workflow）
| # | 題目 | 核心考點 | 埠 |
|---|---|---|---|
| ㊶ | 工作流引擎 (Temporal/Airflow) | DAG 排程、durable execution 事件溯源、重試、saga 補償、審批 | 8041 |
| ㊷ | 分散式排程器 / Cron | 到點觸發、防重複執行(claim+fencing+租約)、misfire 補跑、分片 | 8042 |

### 🅴 延伸 AI/LLM
| # | 題目 | 核心考點 | 埠 |
|---|---|---|---|
| ㊸ | 推薦系統 (召回 + 排序) | 協同過濾 + embedding ANN 召回、特徵排序、特徵存儲、冷啟動 | 8043 |

---

## ⭐ 之後還值得補（Tier 3 產品廣度，尚未開建）
延伸既有組，不另開新組。要動工就說「建 RTB」「建通知服務」等。

| 題目 | 歸組 | 核心考點 |
|---|---|---|
| 廣告競價 RTB / Ad Serving | 🅳 串流 | 即時拍賣、預算節流 pacing、點擊歸因、低延遲競價 |
| 通用通知服務 | 🅰️ 通知 | 多通道 push/SMS/email、偏好/退訂、模板、去重限流 |
| Email / Gmail | 🅰️ 通知 | 郵件儲存、全文搜尋、反垃圾、會話串 |
| 協作白板 Figma/Miro | 🅱️ 即時 | CRDT（補 ⑨ Google Docs 的 OT）、游標、離線合併 |
| 多人遊戲伺服器 | 🅱️ 即時 | authoritative server、tick、lag compensation、狀態同步 |
| 線上判題 / 程式碼沙箱 | 🅼 排程 | 隔離執行、資源限額、佇列、結果回收 |
| Pastebin | 🅳 串流 | 短碼、TTL 過期、存取控制（與 ① QR 部分重疊） |
| CDN 設計 | 🅳 串流 | 邊緣快取、回源、失效、anycast |

## 建題慣例（與既有 43 題一致）
- 結構：`notes.html`(9 段式，內嵌 `diagram.svg`) + `diagram.excalidraw`(可編輯原稿) + `demo/`(public/index.php、src/*.php、README.md)；範本 `patterns/D-stream-storage/01-qr-code-generator/`。
- 環境：PHP 8.4，僅 json + PDO + hash；禁 `mb_*`/`gmp_*`/`openssl_*`/sqlite；共享狀態用 `flock`；執行期寫 `demo/data/`（已 gitignore）。
- 圖：畫 `diagram.excalidraw` → 跑 `php tools/excalidraw-to-svg.php --all` 生成 `diagram.svg` → notes 用 `<img class="diagram-svg" src="diagram.svg">` inline。
- 建完同步更新 `index.html`(進度數、分組區塊與卡片)、分組 `README.md`、`PROGRESS.md`。
- 驗證：`php -l` + 實跑 HTTP 200 + diagram 合法 JSON + SVG 合法 XML。
