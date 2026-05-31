# Amazon 價格追蹤 · PHP Demo

聚焦本題真正的考點：**條件匹配（反向索引 price→watchers）→ 智能去抖狀態機（ARMED/TRIGGERED/COOLDOWN）→ 冪等通知 fan-out**。
刻意不做一堆 CRUD 表單，而是把面試最常被追問的「去抖」做成可實際觀察的狀態機。

## 啟動

```bash
cd demo
php -S localhost:8004 -t public
```

開瀏覽器 <http://localhost:8004>：建立追蹤 → 餵入幾筆價格 → 看哪些觸發提醒、狀態如何流轉。

## 操作流程（建議照做一次）

1. **建立追蹤**：使用者 42、SKU `B0iphone`、目標價 `899`（狀態 = `ARMED`）。
2. **推送 880**：低於目標價 → 觸發 1 筆提醒，狀態變 `COOLDOWN`，已發提醒 = 1。
3. **再推 850**：仍低於目標價，但因在 `COOLDOWN` → **不再發**（這就是去抖：連續低價只發一次）。
4. **推回 950**（> 觸發價 899×1.05 = 943.95 且冷卻結束）→ re-arm 回 `ARMED`。
5. **再推 870** → 重新觸發第 2 筆提醒。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（建立 / 餵價 / 看狀態） |
| POST | `/api/watches` | 建立追蹤 `{ user_id, sku, type, threshold }` |
| POST | `/api/prices/push` | 推送一筆新價格 `{ sku, price }` → 匹配 + 去抖 |
| POST | `/api/prices/tick` | 對所有追蹤的 sku 隨機波動一輪（模擬輪詢） |
| GET | `/api/users/{id}/watches` | 某使用者的追蹤清單與狀態 |

## API 範例

```bash
curl -X POST localhost:8004/api/watches -d "user_id=42&sku=B0iphone&type=target&threshold=899"
curl -X POST localhost:8004/api/prices/push -d "sku=B0iphone&price=880"
curl -X POST localhost:8004/api/prices/push -d "sku=B0iphone&price=850"   # COOLDOWN，不重發
curl localhost:8004/api/users/42/watches
```

## 檔案

```
src/Watchlist.php   watches + 反向索引 watchersFor()（模擬 Redis ZSET 區間查）
src/PriceFeed.php   價格串流/輪詢 + 變更偵測（價格沒變即丟棄）
src/AlertEngine.php 核心：條件匹配 + 去抖狀態機 + 遲滯 re-arm + 冪等去重
public/index.php    路由 + 深色測試頁
data/               執行期產生（已 gitignore）
```

## 去抖狀態機（核心）

```
              價格 <= 觸發價
   ARMED ───────────────────▶ TRIGGERED ──(發提醒,落地)──▶ COOLDOWN
     ▲                                                        │
     │   價格回升 >= 觸發價×(1+遲滯) 且 冷卻結束              │ 冷卻期間達標
     └────────────────────────────────────────────────────────┘ 不重發
```

- **冷卻期**（demo 30 秒）：觸發後一段時間內即使持續低於門檻也不重發。
- **遲滯帶**（demo 5%）：價格須回升超過「觸發價 ×1.05」才 re-arm，避免門檻線上下抖動造成反覆觸發。
- **冪等**：每次觸發產生 `hash(watch_id + 觸發週期)` 冪等鍵，投遞層去重，保證 at-least-once 不變成 many-times。

## ⚠️ 示意 vs 真實

| 項目 | Demo | 真實系統 |
|---|---|---|
| 反向索引 | process 內 `watchersFor()` 過濾 | Redis ZSET `ZRANGEBYSCORE`（O(log N + 命中數)） |
| 價格事件 | JSON 檔 + 手動/隨機 | Kafka 事件流 / CDC（監聽 binlog） |
| 通知投遞 | 記錄成 alert 陣列 | Kafka/SQS 佇列 + Email/Push 服務，分區並行 + 重試/DLQ |
| 儲存 | JSON 檔 | 分片式 DB |

**系統核心邏輯（條件匹配、去抖狀態機、冪等）完全一致**，上生產只需替換基建。
