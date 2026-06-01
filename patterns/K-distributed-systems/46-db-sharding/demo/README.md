# 資料庫分片 · PHP Demo

聚焦關聯式分片（Sharding）的系統設計考點，刻意凸顯它和 NoSQL 一致性雜湊（第 ㉛ 題）**不同的痛**：

**取模分片路由 → scatter-gather 跨片聚合 → 分散式全域 ID（號段）→ re-shard 搬遷**

在單一 PHP process 內，用 **每個分片一個 JSON 檔** 假裝成一台獨立的 MySQL（Vitess / ProxySQL 風格）。

## 啟動

```bash
cd demo
php -S localhost:8046 -t public
```

開瀏覽器 <http://localhost:8046>。預設 N=2 片、號段大小 1000。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（設分片數、插入看落哪片、單片點查、跨片聚合、加分片 re-shard） |
| POST | `/api/insert` | 插入 `{ shard_key, amount?, region? }` → 路由到 `crc32(key) % N` 那片 + 配全域 ID |
| GET | `/api/get?key=` | 依 `shard_key` **單片**點查（只打一台分片，最快） |
| GET | `/api/aggregate` | 跨分片 **scatter-gather**（`op=count\|sum\|top`, `field=amount`, `limit=`） |
| POST | `/api/addshard` | **re-shard**：加一台分片（N→N+1），回報哪些 key 換家、搬遷比例 |
| POST | `/api/config` | 設定 `{ shards }`（會清空資料重來） |
| GET | `/api/overview` | 各分片列數、樣本資料 |

## 動手玩（重現四大核心）

```bash
# 0) 先塞一批資料（測試頁有「一鍵塞 12 筆」按鈕；或手動 insert）
curl -X POST localhost:8046/api/insert -H "Content-Type: application/json" \
     -d '{"shard_key":"user:1001","amount":120,"region":"TW"}'

# 1) 路由：帶 shard_key → 只打「一台」分片（看 shards_scanned=1、routed_to、formula）
curl "localhost:8046/api/get?key=user:1001"

# 2) scatter-gather：沒帶 shard_key 的聚合 → 打「所有」分片再合併（shards_scanned=N）
curl "localhost:8046/api/aggregate?op=count"
curl "localhost:8046/api/aggregate?op=sum&field=amount"
curl "localhost:8046/api/aggregate?op=top&field=amount&limit=5"

# 3) 全域 ID：每筆 insert 回傳的 global_id = shard×號段 + 本地序號 → 跨片永不撞號

# 4) re-shard：加一台分片，看「取模分片」幾乎全搬（moved_pct 很高）
curl -X POST localhost:8046/api/addshard
```

## 檔案

```
src/ShardRouter.php   分片核心：crc32(key)%N 路由、scatter-gather 聚合、號段全域 ID、addShard 搬遷
public/index.php      路由 + 測試頁
data/                 執行期產生（shard_*.json 一檔一分片、meta.json 設定/發號水位）
```

## 環境限制

PHP 8.4 原生、零依賴；僅用 `json` + `hash`（`crc32`）。每檔 `declare(strict_types=1);`。
各分片 JSON 以 `flock`（`LOCK_EX`）原子化寫入 `data/`（自動建目錄）。
**未使用** `mb_*` / `gmp_*` / `openssl_*` / `sqlite` / `PDO`。

## ⚠️ 誠實聲明

這是**單一 process、JSON 檔模擬多台 MySQL**：沒有真實 MySQL、沒有跨片 2PC 分散式交易、沒有線上不停機搬遷（Vitess 的 VReplication）。
但**取模分片路由、scatter-gather 跨片聚合、號段全域 ID、re-shard 換家計算與搬遷** 的邏輯皆為真實可執行——這正是關聯式分片在面試中真正的考點。

### 和第 ㉛ 題（分散式 KV）的關鍵差異

| | 第 ㉛ 題 KV（NoSQL） | 本題 關聯式分片 |
|---|---|---|
| 切分法 | 一致性雜湊環 + vnode | 取模分片 `hash(key) % N`（也可範圍分片） |
| 加機器搬遷量 | 只搬 ~1/N | 取模分片幾乎全搬（本 demo 可實測） |
| 跨節點查詢 | 天生無 JOIN，KV get/put | 沒帶 shard_key → scatter-gather，JOIN/聚合很貴 |
| 主鍵 | 應用自帶 key | 跨片不能 auto_increment → 全域發號（號段/snowflake） |
