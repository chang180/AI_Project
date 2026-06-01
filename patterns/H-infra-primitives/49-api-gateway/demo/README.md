# API Gateway / Load Balancer · PHP Demo

聚焦系統設計考點：**路由（最長前綴）→ 負載均衡（RR / 最少連線 / 一致性雜湊）→ 健康檢查繞過 → 熔斷器 closed/open/half_open**。

## 啟動

```bash
cd demo
php -S localhost:8049 -t public
```

開瀏覽器 <http://localhost:8049>：
1. **一鍵建範例**：`/users`(3 台) + `/orders`(2 台)。
2. **連送 12 次** `/users/42` → 看 RR 平均輪流；切「最少連線」「一致性雜湊」看分布差異。
3. **kill 一台** → 再送請求，看自動繞過只導到健康的。
4. **對某台連續失敗 ×4** → 看熔斷器打開（`open`）後自動繞過；等 10 秒看它轉 `half_open` 放一個試探，成功就 `closed` 恢復。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 深色測試頁 |
| POST | `/api/upstreams` | 註冊服務池 `{ prefix, backends:[...] }` |
| POST | `/api/algo` | 切換 LB 演算法 `{ algo }`（round_robin / least_conn / consistent_hash） |
| POST | `/api/request` | 模擬轉發一次 `{ path, fail?, hashKey? }` → 回挑中的後端 |
| POST | `/api/kill` | 標記後端 down / up `{ prefix, backend, up? }` |
| POST | `/api/reset` | 全部重置 |
| GET | `/api/state` | 目前完整狀態 |

## API 範例

```bash
# 註冊服務池
curl -X POST localhost:8049/api/upstreams -H "Content-Type: application/json" \
  -d '{"prefix":"/users","backends":["users-1","users-2","users-3"]}'

# 連送 6 次，看 round-robin 在三台間平均輪流
for i in $(seq 1 6); do curl -s -X POST localhost:8049/api/request \
  -H "Content-Type: application/json" -d '{"path":"/users/42"}'; echo; done

# kill 一台 → 再送，看自動繞過
curl -X POST localhost:8049/api/kill -H "Content-Type: application/json" \
  -d '{"prefix":"/users","backend":"users-2"}'

# 對後端連續失敗 3 次 → 熔斷打開
for i in $(seq 1 3); do curl -s -X POST localhost:8049/api/request \
  -H "Content-Type: application/json" -d '{"path":"/users/42","fail":true}'; echo; done

curl localhost:8049/api/state
```

## 檔案

```
src/Gateway.php       核心：路由 / 三種 LB 演算法 / 健康檢查繞過 / 熔斷器狀態機（檔案鎖原子更新）
public/index.php      路由 + 深色測試頁（註冊、送請求、kill、看熔斷）
data/                 執行期產生（gateway.json，已 gitignore）
```

## ⚠️ 示意 vs 真實

- **真實系統**：Gateway 真正轉發 HTTP 到後端 IP:port，主動定時健康探測（`/healthz`），共享狀態（後端清單、熔斷器）放在 Redis / etcd 等一致性儲存；多 Gateway 實例水平擴展。
- **本 demo**：後端是 JSON 裡的計數器（非真服務），用 `flock(LOCK_EX)` 把整段「讀狀態 → 跑 LB/熔斷 → 寫回」鎖成單一原子操作，模擬真實 Gateway 對共享狀態的原子更新（單機示意，非真正分散式）。

路由（最長前綴）、三種 LB 演算法、健康檢查繞過、熔斷器 `closed → open → half_open → closed` 的狀態轉移**完全是真實可執行的邏輯**。要上線只需把「JSON 計數器」換成真正的 HTTP 轉發 + 主動探測，決策邏輯原封不動。
