# 分散式鍵值儲存 · PHP Demo

聚焦系統設計考點：**一致性雜湊 + vnode → preference list → quorum N/R/W → 向量時鐘衝突 → read repair → hinted handoff**。
在單一 PHP process 內模擬一個多節點叢集（DynamoDB / Cassandra 風格的 AP 系統）。

## 啟動

```bash
cd demo
php -S localhost:8031 -t public
```

開瀏覽器 <http://localhost:8031>。預設叢集 3 節點 A/B/C，N=3、R=2、W=2、每節點 12 個 vnode。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（環+vnode、preference list、put/get、節點 up/down、並發寫） |
| POST | `/api/put` | 寫入 `{ key, value, [coordinator] }` → quorum W + hinted handoff |
| GET | `/api/get?key=` | 讀取 → quorum R + 向量時鐘調和 + read repair + sibling |
| POST | `/api/node` | 節點動作 `{ action: add\|remove\|up\|down, node }` |
| GET | `/api/ring?key=` | 環/vnode 視圖、hint 概況、（給 key 時）preference list |
| POST | `/api/config` | 設定 `{ N, R, W }` |

## 動手玩（重現五大核心）

```bash
# 1) 看 key 落點與 preference list（順時針前 N 個不同節點）
curl "localhost:8031/api/ring?key=cart:42"

# 2) 寫入並走 quorum W（回傳向量時鐘）
curl -X POST localhost:8031/api/put -H "Content-Type: application/json" \
     -d '{"key":"cart:42","value":"apple"}'

# 3) 讀取走 quorum R（W+R>N 保證讀得到最新）
curl "localhost:8031/api/get?key=cart:42"

# 4) 製造並發 sibling（向量時鐘衝突）：用 down 製造網路分割，
#    讓兩次寫各自看不到對方 → 得到 {A:1} 與 {B:1} 兩個並發版本
curl -X POST localhost:8031/api/node -d '{"action":"down","node":"B"}'
curl -X POST localhost:8031/api/node -d '{"action":"down","node":"C"}'
curl -X POST localhost:8031/api/put  -d '{"key":"k","value":"fromA","coordinator":"A"}'
curl -X POST localhost:8031/api/node -d '{"action":"up","node":"B"}'
curl -X POST localhost:8031/api/node -d '{"action":"up","node":"C"}'
curl -X POST localhost:8031/api/node -d '{"action":"down","node":"A"}'
curl -X POST localhost:8031/api/put  -d '{"key":"k","value":"fromB","coordinator":"B"}'
curl -X POST localhost:8031/api/node -d '{"action":"up","node":"A"}'
curl "localhost:8031/api/get?key=k"     # → 2 個 sibling，conflict=true，並觸發 read repair

# 5) hinted handoff：需要一個落在 preference list 之外的健康節點當代收者。
#    先加第 4 個節點 D，把 C down，寫一個 preference list 含 C 的 key，
#    C 的副本會 hinted handoff 暫存到 D；C 回來後自動交還。
curl -X POST localhost:8031/api/node -d '{"action":"add","node":"D"}'
curl -X POST localhost:8031/api/node -d '{"action":"down","node":"C"}'
curl -X POST localhost:8031/api/put  -d '{"key":"x:20","value":"v","coordinator":"B"}'
curl "localhost:8031/api/ring"          # hints 區可見 {proxy:D, forNode:C}
curl -X POST localhost:8031/api/node -d '{"action":"up","node":"C"}'   # 交還 hint
```

## 檔案

```
src/ConsistentHashRing.php  一致性雜湊環 + vnode；hash(crc32)→順時針查找；preference list；重映射
src/VectorClock.php         向量時鐘：increment / compare(happens-before/並發) / merge
src/Cluster.php             叢集核心：put(quorum W+hinted handoff) / get(quorum R+read repair+sibling)
                            節點 up/down/add/remove；各節點 KV 存 data/node_*.json（flock 原子化）
public/index.php            路由 + 測試頁
data/                       執行期產生（已 gitignore）
```

## 環境限制

PHP 8.4 原生、零依賴；僅用 `json` + `hash`（`crc32`）擴充。每檔 `declare(strict_types=1);`。
共享狀態以 `flock` 原子化寫入 `data/`（自動建目錄）。**未使用** `mb_*` / `gmp_*` / `openssl_*` / `sqlite`。

## ⚠️ 誠實聲明

這是**單一 process 模擬多節點**：沒有真實網路、沒有 gossip 定時心跳、沒有 Merkle tree 反熵背景程序。
但**一致性雜湊環 + vnode、quorum N/R/W、向量時鐘衝突偵測、read repair、hinted handoff** 的邏輯皆為真實可執行——
這也正是分散式 KV 在面試中真正的考點。要變成真叢集，把「各節點一個 JSON 檔」換成「各節點一支 RPC 服務」即可，核心演算法不變。
