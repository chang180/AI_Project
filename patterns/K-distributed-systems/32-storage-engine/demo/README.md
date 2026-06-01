# LSM-tree 儲存引擎 · PHP Demo

聚焦寫密集 KV 引擎的核心結構：**WAL → memtable → flush 成 SSTable → bloom filter 加速讀 → compaction 壓實**。
RocksDB / LevelDB / Cassandra / HBase 的底層都是 LSM-tree。

## 啟動

```bash
cd demo
php -S localhost:8032 -t public
```

開瀏覽器 <http://localhost:8032>，用 PUT / GET / DELETE 操作，並可手動觸發 flush 與 compaction。
（memtable 門檻設為 4 筆，方便快速看到自動 flush。）

## 寫路徑 vs 讀路徑

- **寫（快）**：先 append 到 WAL（順序寫、崩潰可復原）→ 寫進記憶體有序 memtable。
  memtable 滿門檻 → 整批排序寫成一個**不可變 SSTable**（含稀疏索引 + bloom filter），再清空 memtable、截斷 WAL。
- **讀（要多花工）**：先查 memtable（最新）→ 由新到舊掃 SSTable；每個 SSTable 先問它的
  **bloom filter**「可能有嗎」，`false` 直接跳過、不碰磁碟；第一個命中的版本即答案（tombstone = 已刪）。
- **compaction**：合併多個 SSTable 成更少更大的檔，丟掉被覆寫/被刪除（tombstone）的舊版本，
  降低讀放大與空間放大。本 demo 用簡化版 size-tiered（把所有 SSTable 合成一個）。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（操作 + memtable/SSTable 狀態 + bloom 統計） |
| POST | `/api/put` | 寫入 `{ key, value }` |
| POST | `/api/del` | 刪除 `{ key }`（寫 tombstone） |
| GET | `/api/get?key=` | 讀取（找不到或已刪回 `found:false`） |
| POST | `/api/flush` | 手動把 memtable flush 成 SSTable |
| POST | `/api/compact` | 手動 compaction（合併所有 SSTable） |
| GET | `/api/state` | 引擎狀態快照（含 bloom 跳過/讀檔次數） |

## API 範例

```bash
# 寫入幾筆（第 4 筆會觸發自動 flush）
curl -X POST localhost:8032/api/put  -H 'Content-Type: application/json' -d '{"key":"user:1","value":"Alice"}'

# 刪除（寫 tombstone）
curl -X POST localhost:8032/api/del  -H 'Content-Type: application/json' -d '{"key":"user:1"}'

# 讀取
curl 'localhost:8032/api/get?key=user:1'

# 查一個不存在的 key：state.bloom_skips 會增加、sstable_reads 不變（bloom 省了磁碟 I/O）
curl 'localhost:8032/api/get?key=nope:999'

# 手動 flush / compaction
curl -X POST localhost:8032/api/flush
curl -X POST localhost:8032/api/compact
```

## 檔案

```
src/Bloom.php       Bloom filter（位元陣列 + 雙重雜湊，crc32 + fnv1a32）：查不到的 key 不用碰磁碟
src/LsmEngine.php   WAL / memtable / flush / SSTable / 稀疏索引 / bloom / compaction + flock
public/index.php    路由 + 測試頁
data/               執行期產生（wal.log / manifest.json / sstable_*.json，已 gitignore）
```

## 觀察重點

- **bloom 省 IO**：查不存在的 key，`bloom_skips` 增加而 `sstable_reads` 不變 → bloom 確定不在就跳過開檔。
- **tombstone**：DELETE 不是真的清掉，而是寫一個刪除標記；compaction 時才真正丟棄。
- **崩潰復原**：尚未 flush 的寫都在 WAL，重啟（重新建立引擎）時由 WAL 重播回 memtable。
- **讀放大**：同一 key 的新舊版本可能散在多個 SSTable，要由新到舊找；compaction 後變成一個檔。

## ⚠️ 誠實聲明

SSTable 以 **JSON 檔模擬**（真實系統是排序後的二進位 block + 索引）；
但 **WAL、memtable、flush、bloom filter、compaction、tombstone** 的邏輯皆為**真實可執行**，
為單機教學示意，未做真實的分層 leveled compaction 與 block 壓縮。
