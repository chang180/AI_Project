# Bookings.com 短租平台 · PHP Demo

聚焦本題系統設計考點：**並發防超賣 → 區間原子扣減 → hold / TTL 暫留 → confirm / 釋放**。
庫存單位＝**每房每日一格**（room × date），退房日不佔。

## 啟動

```bash
cd demo
php -S localhost:8012 -t public
```

開瀏覽器 <http://localhost:8012>：建房況 → 訂房 → 看房況日曆 → confirm / release / TTL 掃描。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（深色） |
| POST | `/api/rooms` | 建立房源 + 庫存日曆 `{ room_id, name, from, to }` |
| GET | `/api/rooms/{id}/calendar` | 房況日曆（每天 OPEN/HELD/BOOKED） |
| POST | `/api/holds` | 建立保留（防超賣）`{ room_id, checkin, checkout, strategy }` |
| POST | `/api/holds/{id}/confirm` | 付款成功 → HELD→BOOKED |
| POST | `/api/holds/{id}/release` | 付款失敗/取消 → HELD→OPEN |
| POST | `/api/sweep` | 背景 TTL 掃描器：釋放逾期 hold |

`strategy` 可填 `pessimistic`（預設，悲觀鎖 `SELECT FOR UPDATE` 語意）或 `optimistic`（樂觀鎖 / 條件更新 CAS）。

## API 範例（驗證防超賣）

```bash
# 建房源 R1（6/1~6/7 共 7 格）
curl -X POST localhost:8012/api/rooms \
  -d "room_id=R1&name=海景套房&from=2026-06-01&to=2026-06-07"

# 兩個並發訂同一房同日期區間 6/1~6/4 → 只有一個 201，另一個 409
curl -s -X POST localhost:8012/api/holds -d "room_id=R1&checkin=2026-06-01&checkout=2026-06-04" &
curl -s -X POST localhost:8012/api/holds -d "room_id=R1&checkin=2026-06-01&checkout=2026-06-04" &
wait

# 看房況：6/1~6/3 變 HELD，6/4 仍 OPEN（退房日不佔）
curl localhost:8012/api/rooms/R1/calendar
```

## 操作流程（對應面試考點）

1. **建房況**：每房每日一格庫存（OPEN）。
2. **並發訂同房同日期** → 只有一個成功（201），另一個被拒（**409 防重複預訂**）。
3. **hold/TTL**：下單先扣為 HELD 並記 `expires_at`；不付款 → 逾時，下一次操作或 `/api/sweep` 會**自動釋放**成 OPEN 可再訂。
4. **confirm**：付款成功 → HELD→BOOKED；逾時釋放後 confirm 回 `410`，需重新下單（補償語意）。

## 檔案

```
src/InventoryStore.php   庫存日曆 + 用 flock(LOCK_EX) 模擬「DB 交易 / 行鎖」(transaction 臨界區)
src/BookingService.php   區間可用性檢查 + 原子扣減（防超賣）、hold/TTL、confirm/release、樂觀鎖 CAS 示意
public/index.php         路由 + 深色測試頁（房況日曆視覺化）
data/                    執行期產生的庫存 JSON（已被 repo 根目錄 .gitignore 忽略，勿手動建）
```

## ⚠️ 示意 vs 真實

| 項目 | 本 Demo（示意） | 真實系統 |
|---|---|---|
| 交易 / 行鎖 | `flock(LOCK_EX)` 排他檔案鎖 | DB 交易 `BEGIN … COMMIT` |
| 悲觀鎖 | 鎖內逐格檢查再更新 | `SELECT … FOR UPDATE` |
| 樂觀鎖 | 鎖內條件更新 + 比對 affected==天數 | `UPDATE … WHERE status='OPEN'`，比對 affected_rows |
| 防超賣最後防線 | JSON key 唯一 | `(room_id, date)` 主鍵唯一 |
| 庫存表 | JSON 檔 | 分片式 room_inventory 表 |
| TTL 釋放 | 操作時惰性 + `/api/sweep` | 背景定時工作 + 到期索引 |

**並發防超賣、區間原子扣減、hold/TTL、confirm/release、樂觀鎖條件更新等系統邏輯為真實可執行**，演算法與真實 DB 版本一致，只是把「交易 + 鎖」換成檔案鎖、把「表」換成 JSON。
