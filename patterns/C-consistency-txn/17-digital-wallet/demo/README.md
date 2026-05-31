# 數位錢包 / 支付系統 · PHP Demo

聚焦系統設計考點：**雙分錄帳本（append-only）→ 冪等付款（重送不重扣）→ 條件更新防透支 / 防雙花 → 餘額守恆對帳**。
這不是 CRUD：餘額是由帳本推導並物化的快取，帳本（借貸恆等的分錄）才是真相來源。

> 金額一律以**整數分（cents）**儲存與運算，**永不用浮點數**（避免 0.1+0.2≠0.3 吃掉錢）。

## 啟動

```bash
cd demo
php -S localhost:8017 -t public
```

開瀏覽器 <http://localhost:8017>：開兩個帳戶與初始餘額 → 轉帳 → 用<strong>同一個 idempotency key</strong> 再送一次（驗證不重複扣款）→ 故意轉超過餘額（驗證被拒）→ 看帳本分錄與對帳結果（借貸平衡、總額守恆）。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/accounts` | 開帳戶 `{ id, initial }`（initial 單位：分） |
| POST | `/api/transfers` | 轉帳 `{ from, to, amount, key }`（amount 單位：分；key=冪等鍵） |
| GET | `/api/accounts/{id}/balance` | 查餘額 |
| GET | `/api/ledger` | 列出全部分錄（append-only） |
| GET | `/api/reconcile` | 對帳：驗 Σ借=Σ貸 + 重算餘額比對物化值 |
| POST | `/api/reset` | 清空 demo 資料 |

## API 範例

```bash
# 開兩個帳戶（A 給 1000.00 元，B 給 0）
curl -X POST localhost:8017/api/accounts -H 'Content-Type: application/json' -d '{"id":"acct_A","initial":100000}'
curl -X POST localhost:8017/api/accounts -H 'Content-Type: application/json' -d '{"id":"acct_B","initial":0}'

# 轉帳 15.00 元，帶冪等鍵
curl -X POST localhost:8017/api/transfers -H 'Content-Type: application/json' \
  -d '{"from":"acct_A","to":"acct_B","amount":1500,"key":"demo-key-1"}'

# 用「同一個 key」重送 → 回放原結果、status=posted 但 idempotent_replay=true，餘額不再變動
curl -X POST localhost:8017/api/transfers -H 'Content-Type: application/json' \
  -d '{"from":"acct_A","to":"acct_B","amount":1500,"key":"demo-key-1"}'

# 故意轉超過餘額 → 422 rejected「餘額不足（防透支）」
curl -X POST localhost:8017/api/transfers -H 'Content-Type: application/json' \
  -d '{"from":"acct_A","to":"acct_B","amount":99999999,"key":"demo-key-overdraft"}'

# 對帳：Σ借 應等於 Σ貸，且 mismatches 為空
curl localhost:8017/api/reconcile
```

## 檔案

```
src/Ledger.php            append-only 雙分錄帳本：每筆 transfer 產 debit+credit（總和為零）；
                          餘額由分錄推導並物化；條件式扣款防透支；reconcile() 驗借貸平衡 + 重算比對。
src/TransferService.php   轉帳：冪等鍵去重（同鍵回放原結果、不重扣）；鎖內檢查餘額足夠才原子過帳。
public/index.php          路由 + 深色測試頁（開戶 / 轉帳 / 帳本 / 對帳 / 重置）。
data/                     執行期產生（已 gitignore）：accounts.json / ledger.json / idempotency.json / txn_seq.txt
```

## 三道防線怎麼實作

1. **冪等付款**：`TransferService::transfer()` 進鎖後先查 `idempotency.json`。同鍵已完成 → 直接回放存好的結果（`idempotent_replay=true`），**不再扣款**；同鍵但請求內容不同 → 回 `409 conflict`。達成「扣款」副作用 effectively-once。
2. **防透支 / 防雙花**：扣款走 `Ledger::applyBalanceDelta($from, -$amount)`，內部模擬條件更新 `balance + delta >= 0`；不足回 `false` 直接拒，餘額**永不為負**。整段過帳以**檔案鎖序列化**（模擬同帳戶 DB 交易），並發兩筆想花同一筆錢時第二筆被拒。
3. **雙分錄 + 守恆**：每筆轉帳寫成 `{from, D, amount}` 與 `{to, C, amount}` 兩筆 append-only 分錄；`reconcile()` 驗證全帳 **Σ借 = Σ貸**，並由帳本重算每個帳戶餘額與物化值逐一比對。

## ⚠️ 示意 vs 真實

| 元件 | Demo 實作（示意） | 真實系統 |
|---|---|---|
| DB 交易 / 原子過帳 | JSON 檔 + 檔案鎖序列化 | RDBMS 交易（BEGIN/COMMIT）、`SELECT … FOR UPDATE` 或 version CAS |
| 帳本儲存 | `ledger.json`（append-only 陣列） | append-only 表 / commit log，冷熱分層 + 快照 |
| 冪等鍵庫 | `idempotency.json` | Redis / KV，帶 TTL 過期回收 |
| 餘額物化 | 寫時同步維護 `accounts.json` | 寫時同步或背景彙總；前面擋 Redis 快取 |
| 跨分片轉帳 | 單檔本機 | Saga / TCC 兩階段補償 + 對帳兜底 |

> **系統邏輯（雙分錄借貸恆等、append-only 帳本、冪等去重、條件更新防透支/防雙花、餘額守恆對帳）為真實可執行**；僅「DB 交易 / 序列化」以檔案鎖示意。所有操作後全帳 Σ借=Σ貸、總額守恆，已實測。
```
