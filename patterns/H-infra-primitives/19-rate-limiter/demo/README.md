# 分散式限流器 · PHP Demo

聚焦系統設計考點：**令牌桶 / 滑動視窗計數器的放行/拒絕邏輯 → per-key 狀態隔離 → 原子計數 → 429 + Retry-After**。

## 啟動

```bash
cd demo
php -S localhost:8019 -t public
```

開瀏覽器 <http://localhost:8019>：
1. **設定限制**（如「每 10 秒 5 次」、選令牌桶或滑動視窗）。
2. **連打 8 次** → 看前 5 次放行（200）、超出被拒（429，附 Retry-After）。
3. **等幾秒再打** → 看 token 補回 / 視窗滑動後恢復放行。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 深色測試頁 |
| POST | `/api/config` | 設定 `{ algo, limit, window_sec }`，並重置狀態 |
| GET | `/api/check?key=...` | 一次限流檢查 → 放行 200 / 超限 429 + `Retry-After` |
| POST | `/api/reset` | 重置所有 key（或指定 `{ key }`）狀態 |
| GET | `/api/state` | 目前規則設定 |

## API 範例

```bash
# 設定每 10 秒 5 次（令牌桶）
curl -X POST localhost:8019/api/config -H "Content-Type: application/json" \
  -d '{"algo":"token_bucket","limit":5,"window_sec":10}'

# 連打 7 次，觀察前 5 次 allowed=true，第 6、7 次 429
for i in $(seq 1 7); do curl -s "localhost:8019/api/check?key=user-1"; echo; done
```

放行回應帶 `X-RateLimit-Limit` / `X-RateLimit-Remaining`；超限回 `429` + `Retry-After`。

## 檔案

```
src/TokenBucket.php            令牌桶：容量 + 補充速率，依經過時間補 token，tryConsume 放行/拒
src/SlidingWindowCounter.php   滑動視窗計數器：當前窗 + 前一窗加權估計，平滑邊界突刺
src/RateLimiter.php            per-key 狀態管理 + allow(key) 介面，可切換演算法（檔案鎖原子更新）
public/index.php               路由 + 深色測試頁
data/                          執行期產生（已 gitignore）
```

## ⚠️ 示意 vs 真實

- **真實系統**：所有 Gateway 節點共用一份 **Redis**；計數用 `INCR` + `EXPIRE`，令牌桶的「讀-算補充-扣減-寫回」多步用 **Lua 腳本**原子執行，杜絕多節點 read-modify-write 競態。
- **本 demo**：用 JSON 檔當共享狀態，用 `flock(LOCK_EX)` 把整段「讀狀態 → 跑演算法 → 寫回狀態」鎖成**單一原子操作**，模擬 Redis 的原子性（單機示意，非真正分散式）。

兩種演算法的數學邏輯（令牌桶補充、滑動視窗加權）、per-key 隔離、429 + Retry-After 的回應行為**完全是真實可執行的**。要上線只需把 `RateLimiter` 的檔案鎖換成 Redis 原子操作，演算法類別原封不動。
