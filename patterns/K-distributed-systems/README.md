# 🅺 分散式系統

## 共通模式
分散式系統的「**地基題**」——資料怎麼分散儲存、多副本怎麼保持一致、多節點怎麼達成共識與協調。這是面試官最愛往下深挖的一層：考點集中在**一致性、容錯、協調**，以及 CAP 下的取捨。把這六題打透，半本分散式系統就通了。

## 可複用心法
- **一致性雜湊 + 虛擬節點**：把 key 與節點映到同一個雜湊環，加減節點只重映射少量 key；vnode 解決資料傾斜。是分片（KV / 快取 / 物件儲存）的共同基礎。
- **quorum N/R/W**：N 份副本、寫 W 份、讀 R 份；`W+R>N` 保證讀到最新；用來在一致性與可用性間調節。
- **衝突解決**：並發寫用**向量時鐘**判因果/並發（並發則保留 sibling 由上層合併）；或簡化為 LWW（會丟寫）。read repair / Merkle tree 反熵補齊落後副本。
- **順序寫 + 不可變檔**：把隨機寫轉成 append（WAL + memtable→SSTable），配 bloom filter 省 IO、compaction 回收；讀/寫/空間三放大取捨。
- **共識（Raft）**：複製狀態機靠一致的有序日誌；leader election + log replication + 多數 commit + 選舉限制保證安全；奇數節點容忍 ⌊N/2⌋ 故障。
- **協調原語**：分散式鎖一律是**租約**，而租約會因 GC/時鐘誤判過期 → 必須配**單調遞增 fencing token**讓下游拒絕過期持有者；watch / 服務發現 / 設定中心同源。
- **副本 vs 抹除碼**：副本 3x 空間、還原快；抹除碼 ~1.5x 空間、CPU 還原。冷熱資料分別選用。

## 本組題目
| # | 題目 | 該題獨特挑戰 |
|---|---|---|
| 31 | 分散式鍵值儲存（DynamoDB/Cassandra） | 一致性雜湊+vnode、quorum N/R/W、向量時鐘衝突、read repair、hinted handoff |
| 32 | LSM-tree 儲存引擎 | WAL 崩潰復原、memtable→SSTable flush、bloom filter 省 IO、compaction、LSM vs B-tree |
| 33 | 物件儲存 S3 | multipart 上傳、一致性雜湊放置、副本 vs 抹除碼還原、預簽 URL |
| 34 | 分散式快取（Redis Cluster） | 16384 slot 路由與重分片、主從、LRU/LFU 淘汰、穿透/擊穿/雪崩、hot key |
| 35 | 共識演算法 Raft | leader election、log replication、多數 commit、選舉限制、故障重選不丟 log |
| 36 | 分散式鎖 + 服務發現 | 租約鎖、fencing token 防過期誤寫、watch、leader election、服務註冊/設定中心 |
| 46 | MySQL 分庫分表（Vitess） | 分片鍵路由、跨片 scatter-gather/JOIN 之痛、分散式自增 ID、re-shard 搬遷（vs 一致性雜湊 KV） |
| 47 | 分散式交易 / NewSQL（Spanner） | 2PC prepare/commit/abort 回滾、跨片強一致 ACID、Percolator/TrueTime、vs Saga |
