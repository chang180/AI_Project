# 秒殺 / 搶票 Ticketmaster · PHP Demo

聚焦一致性 × 高並發考點：**原子條件扣減防超賣 → 冪等鍵去重 → 每人限購 → 入場令牌削峰**。

## 啟動

```bash
cd demo
php -S localhost:8028 -t public
```

開瀏覽器 <http://localhost:8028>：

1. 點「一鍵壓測模擬」：1000 人搶 100 張 → 看**售出恰為 100、無超賣**。
2. 手動下單：用**相同 idem_key** 重送 → 觀察只扣一次（冪等）。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 互動測試頁 |
| POST | `/api/configure` | 設定場次 `{ name, stock, limit, token_capacity }` |
| POST | `/api/token` | 領入場令牌 `{ user }`（發完回 429 排隊） |
| POST | `/api/buy` | 下單 `{ user, idem_key, token, qty }` → 原子扣減 |
| GET | `/api/stock` | 查庫存（剩餘 / 已售 / 是否售罄） |
| POST | `/api/simulate` | 一鍵壓測 `{ users, stock, limit, use_token }` |

## API 範例

```bash
# 設場次：庫存 3、每人限購 2、令牌上限 5
curl -X POST localhost:8028/api/configure -H 'Content-Type: application/json' \
  -d '{"name":"演唱會","stock":3,"limit":2,"token_capacity":5}'

# 領令牌
curl -X POST localhost:8028/api/token -d '{"user":"alice"}'

# 下單（帶冪等鍵；相同 idem_key 重送不重複扣）
curl -X POST localhost:8028/api/buy \
  -d '{"user":"alice","idem_key":"key-1","token":"<上一步的token>","qty":1}'

# 一鍵壓測：1000 人搶 100 張，驗證無超賣
curl -X POST localhost:8028/api/simulate -d '{"users":1000,"stock":100}'
```

## 檔案

```
src/FlashSale.php   核心：flock 原子條件扣減（防超賣）+ 冪等去重 + 限購 + 令牌池
public/index.php    路由 + 互動測試頁（壓測模擬、手動下單看冪等）
data/               執行期狀態（庫存/訂單/冪等/令牌），已 gitignore，自動建立
```

## ⚠️ 誠實聲明：這是單機教學版

真實秒殺系統的原子扣減靠 **Redis `DECR`/Lua** 或 **DB `UPDATE ... WHERE remaining>0`（行鎖）**，
落單走 **MQ 非同步**，前面再加 **令牌/排隊/CDN 多層削峰**。

本 Demo 用 **`flock` 檔案鎖**把「讀-改-寫」包成臨界區，扮演 Redis 原子操作 / DB 行鎖的角色——
單機、非分散式。但**防超賣（CAS 條件扣減）、冪等去重、每人限購、令牌削峰的邏輯都是真實可執行的**：

- 模擬高並發搶購後，**售出數恰等於庫存、不多不少**（`no_oversell` / `sold_equals_stock` 斷言）。
- 同一 user 帶**相同 idem_key** 重送 → **回原訂單、庫存不再變動**（`idempotency` 斷言）。

要換成真正的分散式扣減，把 `FlashSale::buy()` 裡 `atomic()` 臨界區中的「判斷 + 扣減」
替換成一段 Redis Lua（`if redis.call('GET',k)>0 then return redis.call('DECR',k) end`）即可，
其餘邏輯不變。
