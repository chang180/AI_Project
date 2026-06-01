# CDC Data Pipeline · PHP Demo

聚焦系統設計考點：**變更擷取（CDC）→ 變更日誌（op/before/after/LSN）→ 下游依序冪等套用 → 最終一致**。

模擬 Debezium 風格的資料管線：對「來源表」做 insert/update/delete，
就同步產生一筆變更事件 append 到變更日誌；下游消費者依 LSN 依序、冪等地
套用到衍生視圖（去正規化快取 / 搜尋索引），讓兩邊最終一致。

## 啟動

```bash
cd demo
php -S localhost:8048 -t public
```

開瀏覽器 <http://localhost:8048>。

## 體驗順序

1. **改來源表**：建立 id=1（name/price）→ 看「變更日誌」多了一筆 `op:c` 的事件（含 after、LSN）。
2. **下游套用（增量）**：按按鈕 → 衍生視圖跟著出現 id=1，狀態變「✔ 一致」。
3. **再改**：更新 id=1 的 price → 看新事件 `op:u`（before=舊、after=新）→ 再套用 → 兩邊又一致。
4. **重放全部（驗證冪等）**：把所有事件再餵一次。已套用的會被 `skip(已套用)`，
   結果**完全不變**——這就是冪等套用（用 LSN 去重，重放不會套錯）。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/mutate` | 對來源表寫入 `{ op:c\|u\|d, id, fields }` → 產生變更事件 |
| GET | `/api/changes` | 變更日誌（依 LSN 有序） |
| POST | `/api/consume` | 下游套用 `{ mode: incremental \| replay }` |
| GET | `/api/state` | 來源表 / 衍生視圖 / 位移 / 是否一致 |

## API 範例

```bash
# 對來源表插入一列
curl -X POST localhost:8048/api/mutate \
  -H 'Content-Type: application/json' \
  -d '{"op":"c","id":1,"fields":{"name":"Widget","price":100}}'

# 看變更日誌
curl localhost:8048/api/changes

# 下游增量套用
curl -X POST localhost:8048/api/consume -d '{"mode":"incremental"}'

# 重放全部（冪等）
curl -X POST localhost:8048/api/consume -d '{"mode":"replay"}'

# 看現況與一致性
curl localhost:8048/api/state
```

## 檔案

```
src/SourceTable.php   來源表（模擬主庫一張表）：apply(c/u/d) 回傳 before/after
src/ChangeLog.php     變更日誌（模擬 binlog→topic）：append 事件 + 原子遞增 LSN + 有序讀取
src/Consumer.php      下游消費者：依 LSN 依序 + 冪等套用到衍生視圖 + 維護消費位移
public/index.php      路由 + 測試頁
data/                 執行期產生（已 gitignore）
```

## 三大保證怎麼做到的

- **分區內有序**：每筆事件分配單調遞增 LSN；消費端 `usort` 後依 LSN 由小到大套用。
- **at-least-once**：消費者「套用完才提交位移」；崩潰重啟會從舊位移重拉，可能重送。
- **冪等套用**：`event.lsn <= applied_lsn` 一律跳過（offset 去重），另在視圖記 `_lsn` 來源戳記——
  所以重放整份日誌，結果與只套一次完全相同。

## ⚠️ 誠實聲明

本 demo 用 **JSON 檔模擬 binlog**（真實系統是 Debezium 讀 MySQL/Postgres 的 WAL/binlog → Kafka）。
但**變更擷取（op/before/after）、LSN 有序、下游依序冪等套用、最終一致比對**這些
系統核心邏輯**為真實可執行**。要接真 CDC，把 `ChangeLog` 換成 Kafka consumer、
`SourceTable` 換成真資料庫即可——下游套用邏輯一字不用改。
