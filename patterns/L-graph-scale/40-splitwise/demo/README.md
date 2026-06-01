# Splitwise 帳務分攤 · PHP Demo

聚焦帳務系統考點：**三種分攤（均分 / 指定金額 / 百分比）→ 淨額聚合（守恆 sum=0）→ 債務簡化（最小現金流，最少轉帳）**。金額一律用**整數分**避免浮點誤差。

## 啟動

```bash
cd demo
php -S localhost:8040 -t public
```

開瀏覽器 <http://localhost:8040>，加幾筆不同分法的支出，觀察各人淨餘額（和=0）與一鍵債務簡化的最少轉帳筆數。首次啟動會自動塞入 4 人範例群組與 3 筆支出。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（建成員、加支出、看淨額、債務簡化） |
| POST | `/api/expense` | 新增支出 `{ payer, amount, type, among, desc, key }` |
| GET | `/api/balances` | 每人淨餘額（含守恆驗證 `sum=0`） |
| GET | `/api/settle` | 債務簡化結果（最少轉帳筆數） |
| GET | `/api/state` | 完整狀態（成員 + 支出 + 淨額 + 簡化） |
| POST | `/api/members` | 設定群組成員 `{ members: [...] }` |
| POST | `/api/seed` | 重塞範例資料 |

`amount` 與 exact 的金額皆為**整數分**（`12000` = $120.00）；percentage 為整數百分比（總和需 = 100）。

## API 範例

```bash
# 均分：Amy 付 $30，三人均分
curl -X POST localhost:8040/api/expense -H "Content-Type: application/json" \
  --data-binary '{"payer":"Amy","amount":3000,"type":"equal","among":["Amy","Ben","Cat"],"key":"k1"}'

# 同 key 重送 → 冪等 replay，不重複入帳
curl -X POST localhost:8040/api/expense -H "Content-Type: application/json" \
  --data-binary '{"payer":"Amy","amount":3000,"type":"equal","among":["Amy","Ben","Cat"],"key":"k1"}'

# 看淨餘額（sum 應為 0）
curl localhost:8040/api/balances

# 看最少轉帳建議
curl localhost:8040/api/settle
```

## 分攤方式 `among` 格式

| type | among 格式 | 驗證 |
|---|---|---|
| `equal` | 人名清單 `["Amy","Ben"]` | 餘數逐分配給前面的人 |
| `exact` | `{ "Cat":4000, "Dan":2000 }`（分） | 總和需 = `amount` |
| `percentage` | `{ "Amy":40, "Ben":60 }`（%） | 總和需 = 100 |

## 檔案

```
src/Ledger.php     帳本核心：三種分攤、淨額聚合(守恆)、債務簡化(最小現金流)、冪等去重；JSON+flock
public/index.php   路由 + 測試頁
data/              執行期產生（已 gitignore）
```

## ⚠️ 誠實聲明

這是**單機帳本**（JSON 檔模擬 DB、`flock` 模擬交易原子性），不含分片、多區域、真實多幣別匯率源。
但**分攤計算、淨額守恆（sum=0）、債務簡化（最小現金流）、冪等去重、整數分**皆為**真實可執行邏輯**，正是面試的核心考點。

要升級為正式系統：把 `Ledger` 的 JSON 持久化換成分片式 DB（append-only `expenses + splits` + 增量維護的 `balances` 快取），冪等鍵改用 DB 唯一索引，匯率接真實匯率源即可，演算法不變。
