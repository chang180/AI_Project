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

## 🚀 Tier 4 深化方向（依「概念缺口」排序，尚未開建）
43 題已覆蓋核心模式，這四個方向是「補完整張地圖」最有價值的缺口（不是換產品名，而是新概念）。

### 方向一：可觀測性「補完三支柱」（目前只有 metrics）
| 題目 | 歸組 | 核心考點 |
|---|---|---|
| 分散式追蹤 Distributed Tracing | 🅹 可觀測性 | trace id / span 串跨服務時間軸、取樣、因果鏈、找慢點（面試高頻、目前缺） |
| 日誌系統 Log Aggregation (ELK) | 🅹 可觀測性 | 海量日誌收集→索引(倒排)→查詢、結構化、保留與分層 |

### 方向二：關聯式資料庫的世界（K 組偏 NoSQL，這塊薄）
| 題目 | 歸組 | 核心考點 |
|---|---|---|
| MySQL 分庫分表 Sharding (Vitess) | 🅺 分散式系統 | 分片鍵、跨片 JOIN/聚合、分散式自增、re-shard、讀寫分離（與一致性雜湊 KV 不同） |
| 分散式交易 / NewSQL (Spanner) | 🅺 分散式系統 | 2PC、Percolator、TrueTime、跨片強一致 ACID（補 Raft + Saga 的拼圖） |
| CDC / 資料管線 (Debezium) | 🅼 排程 / 🅳 串流 | 監聽 binlog→串流下游、ETL、最終一致同步 |

### 方向三：流量入口層（目前只有限流器，缺整個門面）
| 題目 | 歸組 | 核心考點 |
|---|---|---|
| API Gateway / 負載均衡器 | 🅷 基建 | 路由、認證、限流、熔斷、L4/L7 LB、健康檢查、一致性雜湊導流 |
| DNS 系統 | 🅷 基建 | 階層解析、快取 TTL、GeoDNS 就近導流、anycast |
| CDN 設計 | 🅷 基建 / 🅳 串流 | 邊緣快取、回源、快取失效、anycast |

### 方向四：大數據 / 分析 / AI 檢索
| 題目 | 歸組 | 核心考點 |
|---|---|---|
| 向量資料庫 / ANN 檢索 | 🅶 搜尋 / 🅴 AI | HNSW / IVF 近似最近鄰、嵌入索引、召回（補 ⑬RAG、㊸推薦的地基） |
| 批次處理 MapReduce / Spark | 🅳 串流 | map→shuffle→reduce、PB 級離線、與串流互補的另一半 |
| 資料倉儲 / OLAP 列式儲存 | 🅳 串流 | 列式儲存、向量化執行、分析型查詢（vs OLTP） |

## 🧩 知名「綜合題」（練組合既有模式，尚未開建）
不另開新組；重點是把已學的模式拼起來解一個完整知名產品。
| 題目 | 組合了哪些既有模式 | 新增/獨特考點 |
|---|---|---|
| TikTok / Netflix 短影音 | YouTube(⑩) + 推薦(㊸) + CDN + Feed(⑮) | 影片召回排序、預載、興趣探索 vs 利用 |
| 電商訂單系統 | 購物車 + 秒殺(㉘) + 錢包(⑰) + 工作流(㊶) | 訂單狀態機、庫存預扣、支付編排 saga、對帳 |
| DoorDash / Uber Eats | RoboTaxi(⑤地理媒合) + 通知 + 即時追蹤 | 三邊市場（食客/店家/外送員）、ETA、批量指派 |
| Tinder / Bumble 配對 | 附近的人(⑱geohash) + 推薦(㊸) | 雙向喜歡配對、滑卡佇列、防重複曝光 |
| 反詐騙 / 風控系統 | 廣告聚合(㉒) + 串流 + 規則引擎 | 即時特徵、規則 + 模型評分、名單、回饋迴路 |
| 端對端加密通訊 Signal (E2EE) | Messenger(⑦) + 認證(㉔) | 金鑰交換、雙棘輪、前向保密、群組金鑰 |

## 建題慣例（與既有 43 題一致）
- 結構：`notes.html`(9 段式，內嵌 `diagram.svg`) + `diagram.excalidraw`(可編輯原稿) + `demo/`(public/index.php、src/*.php、README.md)；範本 `patterns/D-stream-storage/01-qr-code-generator/`。
- 環境：PHP 8.4，僅 json + PDO + hash；禁 `mb_*`/`gmp_*`/`openssl_*`/sqlite；共享狀態用 `flock`；執行期寫 `demo/data/`（已 gitignore）。
- 圖：畫 `diagram.excalidraw` → 跑 `php tools/excalidraw-to-svg.php --all` 生成 `diagram.svg` → notes 用 `<img class="diagram-svg" src="diagram.svg">` inline。
- 建完同步更新 `index.html`(進度數、分組區塊與卡片)、分組 `README.md`、`PROGRESS.md`。
- 驗證：`php -l` + 實跑 HTTP 200 + diagram 合法 JSON + SVG 合法 XML。
