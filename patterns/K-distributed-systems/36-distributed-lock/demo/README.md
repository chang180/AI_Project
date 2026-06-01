# 分散式鎖 + 服務發現 · PHP Demo

聚焦系統設計考點：**租約鎖（lease）→ fencing token 防護 → watch → leader election → 服務發現 → 設定中心**。
單機檔案模擬 etcd / ZooKeeper 風格的協調服務（CoordService），所有操作走 `flock` 互斥，模擬「線性一致的複製狀態機」。

## 啟動

```bash
cd demo
php -S localhost:8036 -t public
```

開瀏覽器 <http://localhost:8036>，依測試頁的「推薦演示順序」實際操作 fencing token 場景。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（六種協調原語） |
| POST | `/api/lock` | acquire `{ key, owner, ttl }` → 回 **fencing token** |
| POST | `/api/unlock` | release `{ key, owner }` |
| POST | `/api/renew` | renew/heartbeat `{ key, owner, ttl }` |
| POST | `/api/fence-write` | 帶 token 寫下游資源 `{ resource, token, payload }`（舊 token 被拒） |
| POST | `/api/campaign` | leader election `{ election, candidate, ttl }` |
| GET | `/api/leader` | 查目前 leader `?election=` |
| POST/GET | `/api/watch` | POST 註冊 `{ key }` → watcherId；GET poll `?key=&watcherId=` |
| POST/GET | `/api/config` | put `{ key, value }` / get `?key=` |
| POST | `/api/register` | 服務註冊/心跳 `{ service, instance, addr, ttl }` |
| GET | `/api/discover` | 服務發現 `?service=` |
| GET | `/api/snapshot` | 狀態快照 |

## 核心考點：fencing token 場景（用 curl 重現）

```bash
# 1) A 取得鎖（ttl=1 秒，等下會過期），記下回傳的 fencingToken（假設 = 1）
curl -s -X POST localhost:8036/api/lock -d '{"key":"resource-x","owner":"A","ttl":1}'

# 2) 等 >1 秒讓 A 租約過期，B 取得同一把鎖 → 拿到更大的 token（= 2）
curl -s -X POST localhost:8036/api/lock -d '{"key":"resource-x","owner":"B","ttl":30}'

# 3) B 用 token 2 寫資源 → 接受
curl -s -X POST localhost:8036/api/fence-write -d '{"resource":"db-row-1","token":2,"payload":"by-B"}'

# 4) A「GC 暫停後醒來」用舊 token 1 寫資源 → 被 fence 拒絕（1 <= 2）
curl -s -X POST localhost:8036/api/fence-write -d '{"resource":"db-row-1","token":1,"payload":"by-A-stale"}'
```

## 服務發現 / leader election

```bash
# 註冊兩個 instance 後 discover
curl -s -X POST localhost:8036/api/register -d '{"service":"payment","instance":"inst-1","addr":"10.0.0.1:8080","ttl":30}'
curl -s -X POST localhost:8036/api/register -d '{"service":"payment","instance":"inst-2","addr":"10.0.0.2:8080","ttl":30}'
curl -s localhost:8036/api/discover?service=payment

# 競選 leader（搶到鎖即當選，epoch = fencing token）
curl -s -X POST localhost:8036/api/campaign -d '{"election":"scheduler","candidate":"nodeA","ttl":30}'
```

## 檔案

```
src/CoordService.php   協調服務本體：租約鎖 / fencing token / watch / 選主 / 服務發現 / config（flock 臨界區）
public/index.php       路由 + 測試頁
data/                  執行期產生的 state.json（已 gitignore）
```

## ⚠️ 誠實聲明

這是**單機模擬**：用一個 `state.json` + `flock(LOCK_EX)` 模擬「被 Raft 複製、線性一致的單一狀態機」，
**沒有**真正的多節點共識 / 網路 / 故障容錯。

但**租約鎖、fencing token 防護、watch、leader election、服務發現的協調邏輯都是真實可執行的**——
尤其可實際演示「舊 token 被 fence 拒絕」這個分散式鎖最重要的考點。

要做成真叢集，把底層狀態機換成 etcd / ZooKeeper 的 client（鎖 → lease+key、選主 → ephemeral node、
fencing token → `mod_revision` / `zxid`），上層原語介面不變。
