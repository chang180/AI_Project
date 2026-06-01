# 📈 題庫擴充 Roadmap（31 題起）

> 現況：30 / 30 題完成（分組 A–J，見 [PROGRESS.md](PROGRESS.md) 與 [index.html](index.html)）。
> 本檔是「之後值得補的題目」的規劃清單，**尚未開建**。要動工時，對 Claude 說「**建 K 組**」「**建 ㉛ ㉟**」之類即可接續。

## 為什麼擴充
現有 30 題偏「**產品/應用題 + 少數平台積木**」。要面試「全面」，最大缺口是**分散式系統的『地基題』**——鍵值儲存、共識、儲存引擎、分散式快取這類被所有系統共用、面試官最愛深挖的底層題；以及幾個**大規模經典設計**（爬蟲、搜尋引擎、地圖、物件儲存）與**排程/工作流/推薦**維度。

優先序（依需求）：**地基 > 經典設計 ≈ 排程/工作流/推薦 > 產品廣度**。

每題沿用既有慣例：`notes.html`(9 段式) + `diagram.excalidraw` + 可跑 PHP demo（原生、零依賴、禁 `mb_*`、`demo/data/` 已 gitignore）。圓圈編號 ㉛㉜㉝㉞㉟㊱㊲…，埠 8031 起。

---

## 🅺 Tier 1 — 分散式資料儲存（新組 `K-distributed-storage`）★最高優先・地基
| # | 題目 | 目錄 | demo 核心考點（非 CRUD） | 埠 | 建議 |
|---|---|---|---|---|---|
| ㉛ | 分散式鍵值儲存 (DynamoDB/Cassandra) | `K-distributed-storage/31-kv-store` | **一致性雜湊環 + 虛擬節點**、quorum 讀寫 (R/W/N)、**向量時鐘**衝突解決、gossip 成員、hinted handoff | 8031 | 55 |
| ㉜ | LSM-tree 儲存引擎 | `K-distributed-storage/32-storage-engine` | memtable→SSTable flush、WAL、**compaction**、bloom filter 加速查不到、對比 B-tree 讀寫放大 | 8032 | 50 |
| ㉝ | 物件儲存 S3 | `K-distributed-storage/33-object-storage` | 分塊上傳 + 一致性雜湊放置 + 副本/**erasure coding** 取捨、metadata 服務、預簽 URL | 8033 | 48 |
| ㉞ | 分散式快取 (Redis Cluster) | `K-distributed-storage/34-distributed-cache` | 一致性雜湊分片 + 主從副本、**淘汰 LRU/LFU**、快取穿透/擊穿/雪崩、hot key 處理 | 8034 | 45 |

## 🅻 Tier 1 — 分散式協調/共識（新組 `L-coordination-consensus`）★最高優先・地基
| # | 題目 | 目錄 | demo 核心考點 | 埠 | 建議 |
|---|---|---|---|---|---|
| ㉟ | 共識演算法 Raft | `L-coordination-consensus/35-raft-consensus` | **leader election**、複製日誌 + commit index、安全性(只投給 log 夠新者)、複製狀態機重放 | 8035 | 55 |
| ㊱ | 分散式鎖 + 服務發現 (etcd/ZooKeeper) | `L-coordination-consensus/36-distributed-lock` | 租約 lease + **fencing token** 防過期誤寫、watch 通知、leader election、設定中心/服務註冊 | 8036 | 45 |

## 🅼 Tier 2 — 圖與大規模演算法（新組 `M-graph-scale`）・經典設計
| # | 題目 | 目錄 | demo 核心考點 | 埠 | 建議 |
|---|---|---|---|---|---|
| ㊲ | 網頁爬蟲 Web Crawler | `M-graph-scale/37-web-crawler` | BFS frontier、**URL 去重 (bloom filter)**、politeness/robots、分散式 frontier 分片、重抓排程 | 8037 | 48 |
| ㊳ | 網頁搜尋引擎（全鏈） | `M-graph-scale/38-search-engine` | 倒排索引**分片 + scatter-gather**、**PageRank**、查詢解析、排序融合、結果快取（延伸 ㉖ 全文搜尋） | 8038 | 52 |
| ㊴ | 地圖路徑規劃 (Google Maps) | `M-graph-scale/39-maps-routing` | 圖模型、**Dijkstra/A***、contraction hierarchies 預處理、地圖瓦片服務、ETA 即時路況 | 8039 | 50 |
| ㊵ | Splitwise 帳務分攤 | `M-graph-scale/40-splitwise` | 債務有向圖、**最小現金流簡化**、交易冪等、群組對帳守恆 | 8040 | 40 |

## 🅽 Tier 2 — 排程/工作流編排（新組 `N-scheduling-workflow`）・排程/工作流
| # | 題目 | 目錄 | demo 核心考點 | 埠 | 建議 |
|---|---|---|---|---|---|
| ㊶ | 工作流引擎 (Temporal/Airflow) | `N-scheduling-workflow/41-workflow-engine` | **DAG 排程**、durable execution(事件溯源重放)、重試/補償 **saga**、狀態持久化、人工審批節點 | 8041 | 52 |
| ㊷ | 分散式排程器 / Cron | `N-scheduling-workflow/42-distributed-scheduler` | 分散式定時觸發、**防重複執行 (leader/lock)**、錯過補跑 misfire、時間輪/分片、至少一次 | 8042 | 42 |

## 🅴 Tier 2 — 延伸既有 AI/LLM 組・推薦
| # | 題目 | 目錄 | demo 核心考點 | 埠 | 建議 |
|---|---|---|---|---|---|
| ㊸ | 推薦系統 (召回 + 排序) | `E-ai-llm/43-recommendation` | **candidate generation**(協同過濾 / embedding ANN) + **ranking** 模型、特徵存儲、線上線下一致、冷啟動 | 8043 | 50 |

## Tier 3 — 產品廣度（選配，較低優先，延伸既有組不另開新組）
- **🅳 串流**：廣告競價 RTB（即時拍賣 + 預算節流 pacing + 歸因）
- **🅰️ 通知**：通用通知服務（多通道 push/SMS/email + 偏好退訂 + 去重）；Email/Gmail（儲存 + 搜尋 + 反垃圾）
- **🅱️ 即時**：協作白板 Figma/Miro（**CRDT**，補 ⑨ Google Docs 的 OT）；多人遊戲伺服器（authoritative server + tick + lag compensation）
- **小品**：Pastebin、線上判題沙箱、CDN 設計

---

## 建議首批
依優先序，建議第一批做 **Tier 1 地基 6 題（㉛–㊱，K 組 + L 組）**——缺最兇、面試最常深挖、概念互相支撐（一致性雜湊→KV；Raft→鎖/協調）。想輕量先試，可挑 **㉛ KV store + ㉟ Raft** 兩支「鎮店地基題」。

## 建題慣例（與既有 30 題一致）
- 結構：`notes.html`(9 段式) + `diagram.excalidraw`(藍=主流程 綠=讀/回應 黃=基建) + `demo/`(public/index.php、src/*.php、README.md)；範本 `patterns/D-stream-storage/01-qr-code-generator/`。
- 環境：PHP 8.4，僅 json + PDO + hash；禁 `mb_*`/`gmp_*`/`openssl_*`/sqlite；共享狀態用 `flock`；執行期寫 `demo/data/`（已 gitignore）。
- 建完同步更新 `index.html`(進度數、分組區塊與卡片)、分組 `README.md`、`PROGRESS.md`。
- 驗證：`php -l` + 實跑 HTTP 200 + diagram 合法 JSON。
