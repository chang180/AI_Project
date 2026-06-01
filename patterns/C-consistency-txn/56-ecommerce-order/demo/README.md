# 電商下單系統 · PHP Demo

綜合題（一致性 × 交易）：**訂單狀態機（合法轉移）→ 下單付款 Saga 編排與反向補償 → 冪等下單 → 庫存原子預扣（防超賣）**。

## 啟動

```bash
cd demo
php -S localhost:8056 -t public
```

開瀏覽器 <http://localhost:8056>：

1. **正常下單**：下一張單 → 看狀態 `CREATED→PAID→SHIPPED`，庫存與錢包扣減。
2. **注入失敗**：選「扣款失敗 / 確認失敗」→ 看 saga 反向補償（退款、釋放庫存）→ 訂單 `CANCELLED`，庫存/錢包**原樣回復**。
3. **冪等重送**：用**相同 idem_key** 再下一次 → 回**原訂單**，不重複扣庫存/扣款。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 互動測試頁 |
| POST | `/api/order` | 下單走 saga `{ user, sku, qty, idem_key }` |
| POST | `/api/fail` | 下單但注入某步失敗 `{ user, sku, qty, idem_key, at: pay\|confirm }` |
| POST | `/api/advance` | 推進訂單狀態 `{ order_id, to }`（非法轉移回 409） |
| GET | `/api/order/{id}` | 查單（含狀態與 saga 軌跡） |
| GET | `/api/state` | 全局狀態（庫存 / 錢包 / 訂單） |
| POST | `/api/reset` | 重置 demo |

## API 範例

```bash
# 正常下單（成功會走到 SHIPPED）
curl -X POST localhost:8056/api/order -H 'Content-Type: application/json' \
  -d '{"user":"alice","sku":"sku-1","qty":1,"idem_key":"ord-A"}'

# 冪等：相同 idem_key 重送 → 回原訂單，不重複扣
curl -X POST localhost:8056/api/order -d '{"user":"alice","sku":"sku-1","qty":1,"idem_key":"ord-A"}'

# 注入扣款失敗 → saga 反向補償（釋放庫存）→ CANCELLED
curl -X POST localhost:8056/api/fail -d '{"sku":"sku-1","qty":1,"at":"pay"}'

# 注入確認失敗 → 補償（退款 + 釋放庫存）→ CANCELLED
curl -X POST localhost:8056/api/fail -d '{"sku":"sku-1","qty":1,"at":"confirm"}'

# 推進狀態（合法）：SHIPPED → DONE
curl -X POST localhost:8056/api/advance -d '{"order_id":"ord_00001","to":"DONE"}'
# 非法轉移會回 409，例如 DONE → PAID
```

## 檔案

```
src/OrderStateMachine.php  訂單狀態機：合法轉移白名單（CREATED→PAID→SHIPPED→DONE / CANCELLED）
src/OrderSaga.php          Saga 編排：3 步正向動作 + 反向補償；flock 原子預扣防超賣；冪等下單
public/index.php           路由 + 互動測試頁（saga 流轉 / 注入失敗看補償 / 冪等重送）
data/                      執行期狀態（庫存/錢包/訂單/冪等鍵），已 gitignore，自動建立
```

## ⚠️ 誠實聲明：這是單機模擬版

真實電商把**訂單服務 / 庫存服務 / 支付服務**拆成獨立服務，用**訊息佇列**做非同步 Saga 編排，
補償透過反向事件驅動，達到跨服務的**最終一致**。

本 Demo 在**單機**用 `flock` 把整段 Saga 的「讀-改-寫」包成臨界區（模擬 DB 行鎖 / 分散式編排的最終結果）——
不是真正的多服務、多訊息佇列。但以下邏輯**都是真實可執行的**：

- **訂單狀態機**：只允許白名單內的合法轉移，非法轉移（如 `DONE→PAID`）被拒。
- **Saga 補償**：任一步失敗 → 後進先出反向補償（退款、釋放庫存）→ 訂單 `CANCELLED`，
  庫存與錢包**回到下單前的數值**（最終一致）。
- **冪等下單**：同 `idempotency_key` 重送 → 回原訂單，**不重複扣庫存/扣款**。
- **庫存原子預扣**：臨界區內「`stock>=qty` 才扣」→ **防超賣**。

要換成真正的分散式 Saga，把 `OrderSaga::placeOrder()` 內各步驟改成對庫存/支付服務的呼叫，
把補償改成發送反向補償事件即可——**狀態機與補償的編排邏輯不變**。
