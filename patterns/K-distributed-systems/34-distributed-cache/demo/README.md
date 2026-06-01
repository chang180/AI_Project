# 分散式快取 (Redis Cluster) · PHP Demo

聚焦系統設計考點：**16384 slot 路由 → 多 shard 主從 → LRU/LFU 淘汰 → 穿透/擊穿/雪崩三大問題與緩解**。

## 啟動

```bash
cd demo
php -S localhost:8034 -t public
```

開瀏覽器 <http://localhost:8034>，依測試頁四個區塊操作。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/set` | `{ key, value, ttl }` → 回 slot / shard / 是否淘汰誰 |
| GET | `/api/get` | `?key=` → 回 slot / shard / 命中 |
| POST | `/api/policy` | `{ policy:lru\|lfu\|noeviction, maxKeys }` 切換淘汰策略與上限 |
| POST | `/api/node` | `{ action:add\|remove\|failover, node }` 加/移除節點看 slot 搬移、replica 升主 |
| GET | `/api/slots` | slot→節點映射 + 各 shard 概觀（可加 `?key=` 查某 key 路由） |
| POST | `/api/scenario` | `{ type:penetration\|breakdown\|avalanche, mitigate, n }` 三大問題示範 |

## 核心邏輯（皆為真實可執行）

- **slot 路由**：`slot = crc32(key) % 16384`（近似 Redis 的 crc16），查 slotmap 得 shard。支援 `{tag}` hash tag 讓相關 key 落同一 slot。
- **重分片**：加節點只從現有節點勻出一批 slot 給新節點、移除節點把 slot 併回其他節點，**只搬受影響的 slot 區段**（回傳 `moved`），slot 總數恆為 16384。
- **主從**：每 shard 一個 master + 一份 replica，寫 master 後同步 replica；`failover` 把 replica 升為 master。
- **淘汰**：maxKeys（近似 maxmemory）滿時，LRU 淘汰最久未用、LFU 淘汰最少使用、noeviction 拒寫；回傳 `evicted` 顯示淘汰誰。
- **三大問題**：
  - 穿透：查不存在的 key → `dbCalls` = 請求數；緩解（空值快取 + 布隆過濾器）→ `dbCalls` ≈ 0。
  - 擊穿：熱 key 過期併發回源 → `dbCalls` = 併發數；緩解（singleflight 互斥鎖）→ `dbCalls` ≈ 1。
  - 雪崩：大量 key 同一 TTL → `spreadSeconds` = 0（同時過期）；緩解（TTL 抖動）→ 過期時間散開。

## API 範例

```bash
# set / get
curl -X POST localhost:8034/api/set -H "Content-Type: application/json" -d '{"key":"user-42","value":"Alice","ttl":60}'
curl "localhost:8034/api/get?key=user-42"

# 加節點看哪些 slot 換主
curl -X POST localhost:8034/api/node -H "Content-Type: application/json" -d '{"action":"add","node":"shardD"}'

# 設小 maxmemory 觸發 LRU 淘汰（用 {tag} 讓 key 落同一 shard）
curl -X POST localhost:8034/api/policy -H "Content-Type: application/json" -d '{"policy":"lru","maxKeys":3}'
curl -X POST localhost:8034/api/set -H "Content-Type: application/json" -d '{"key":"{g}:k1","value":"1"}'

# 穿透：比較緩解前後的 dbCalls
curl -X POST localhost:8034/api/scenario -H "Content-Type: application/json" -d '{"type":"penetration","mitigate":false,"n":6}'
curl -X POST localhost:8034/api/scenario -H "Content-Type: application/json" -d '{"type":"penetration","mitigate":true,"n":6}'
```

## 檔案

```
src/SlotMap.php    16384 hash slot 與 slot→節點映射、重分片（只搬受影響 slot）
src/CacheNode.php  單 shard：master+replica、TTL、LRU/LFU 淘汰、升主
src/Cluster.php    路由組裝 + 穿透/擊穿/雪崩三場景與緩解 + 布隆過濾器
public/index.php   路由 + 測試頁
data/              執行期產生（已 gitignore）
```

## ⚠️ 誠實聲明

- **單機用檔案模擬多個 shard**，無真正網路、叢集 gossip、自動故障偵測。
- slot 雜湊用 `crc32 % 16384` **近似** Redis 真正的 `crc16 % 16384`（本環境禁用相關擴充）；兩者皆為「同 key 必同 slot、分佈均勻」的雜湊取模，**路由邏輯完全相同**。
- 其餘 16384 slot 路由、重分片只搬受影響 slot、主從複製與升主、LRU/LFU 淘汰、穿透/擊穿/雪崩三問題與緩解，**皆為真實可執行的邏輯**。
- 共享狀態以 `flock` 保護，寫入 `data/`（自動建目錄）。
