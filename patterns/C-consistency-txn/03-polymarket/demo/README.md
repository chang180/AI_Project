# Polymarket 預測市場 · PHP Demo

聚焦一致性/交易考點：**限價單委託簿（order book）撮合 → 凍結保證金 → 成交原子過帳 → 防超額/防雙花 → 事件溯源**。

不是 CRUD：核心是「order book 撮合 + 資金一致性」。

## 啟動

```bash
cd demo
php -S localhost:8003 -t public
```

開瀏覽器 <http://localhost:8003>，用表單下限價單，觀察委託簿、成交、帳戶餘額/持倉與事件日誌。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（委託簿 / 最近成交 / 帳戶 / 事件日誌） |
| POST | `/api/order` | 下限價單 `{ user, side: buy\|sell, price, qty }` |
| POST | `/api/resolve` | 市場到期結算 `{ winner: YES\|NO }` |
| POST | `/api/reset` | 重置 demo 資料 |
| GET | `/api/state` | 完整狀態 JSON（委託簿/成交/帳戶/事件） |

## 操作流程（建議照順序體驗）

```bash
curl -X POST localhost:8003/api/reset

# 1) bob 掛買單 YES 10 股 @0.60 → 凍結 6.0 現金，無對手 → 掛在買簿
curl -X POST localhost:8003/api/order -d "user=bob&side=buy&price=0.60&qty=10"

# 2) alice 掛賣單 YES 6 股 @0.55 → 與 bob@0.60 撮合成交 6 股
#    成交價=被動單(maker)價=0.60；bob 剩 4 股留在簿上（部分成交）
curl -X POST localhost:8003/api/order -d "user=alice&side=sell&price=0.55&qty=6"

# 3) 防超額：bob 餘額不足下大單 → 凍結保證金失敗 → 直接拒絕（餘額不為負）
curl -X POST localhost:8003/api/order -d "user=bob&side=buy&price=0.99&qty=1000"

# 4) 防雙花：alice 賣超過持倉 → 凍結股份失敗 → 直接拒絕
curl -X POST localhost:8003/api/order -d "user=alice&side=sell&price=0.30&qty=1000"

# 5) 市場結算 YES 勝：持 YES 者每股兌現 1 元
curl -X POST localhost:8003/api/resolve -d "winner=YES"
```

## 檔案

```
src/OrderBook.php        限價單委託簿；價格-時間優先撮合；部分成交；產生成交(trade)
src/Ledger.php           帳本/錢包；凍結保證金 hold()、凍結股份 freezeShares()、
                         成交原子過帳 settleTrade()、條件更新防超額/防雙花（餘額永不為負）
src/MatchingEngine.php   撮合引擎；全域鎖序列化下單（單執行緒語意）；串接凍結→撮合→過帳；
                         市場結算 resolve()
src/EventLog.php         事件日誌（append-only）；每個關鍵動作寫一筆不可變事件 → 可重放/審計
public/index.php         路由 + 深色測試頁
data/                    執行期產生（已 gitignore）：events.log.json / ledger.json /
                         book.json / trades.json / engine.lock
```

## 設計對應（demo ↔ 真實系統）

| Demo 寫法 | 真實系統 |
|---|---|
| 全域 `engine.lock` 檔案鎖序列化 submit | 撮合核心單執行緒 / 單分片（如 LMAX Disruptor），無鎖、確定性 |
| `Ledger::mutate()` 在檔案鎖內讀-改-寫 | 關聯式 DB 交易 `BEGIN…COMMIT` |
| `hold()` 條件判斷 `available >= amount` | `UPDATE accounts SET available=available-? WHERE available >= ?`（受影響列數=0 即拒） |
| `EventLog` append JSON 陣列 | Kafka commit log / event store，可重放重建狀態 |
| 預設給帳戶種 YES 持倉 | 以 $1 鑄造一組 1 YES + 1 NO 的完整集合（complete set） |

## ⚠️ 示意 vs 真實

用 JSON 檔 + 檔案鎖模擬「DB 交易 / 單執行緒序列化」屬**示意**；而
**撮合演算法、價格-時間優先、部分成交、凍結保證金、成交原子過帳、防超額/防雙花、事件溯源**
等**系統邏輯為真實可執行**（現金與股份在所有操作後皆守恆）。

生產級替換：撮合核心改 in-memory 單執行緒引擎 + 順序事件日誌；帳本改關聯式 DB 交易（條件 UPDATE / `SELECT … FOR UPDATE` / 樂觀鎖 version）；事件日誌改 Kafka，下游做結算、對帳與分析。
