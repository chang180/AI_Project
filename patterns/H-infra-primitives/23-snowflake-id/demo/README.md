# Snowflake 唯一 ID 產生器 · PHP Demo

聚焦系統設計考點：**64-bit 位元佈局 → 同毫秒序號遞增 → 時鐘回撥處理 → 跨請求單調 → 雙向解碼**。

## 啟動

```bash
cd demo
php -S localhost:8023 -t public
```

開瀏覽器 <http://localhost:8023>：
- 按「產生一個 ID」看單一 ID 與解碼結果。
- 「批次產生 N 個」觀察**同一毫秒內 seq 0,1,2,… 依序遞增**、跨毫秒歸零。
- 貼任一 ID 解碼，驗證雙向還原。
- 頁面上方畫出 64-bit 位元佈局示意。

機器 id 預設為 `1`，可用環境變數覆寫（真實系統由 ZooKeeper/etcd 分配）：

```bash
MACHINE_ID=42 php -S localhost:8023 -t public
```

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（產生 / 批次 / 解碼 / bit 佈局示意） |
| POST | `/api/id` | 產生一個 ID → `{ id, id_str, decoded }` |
| POST | `/api/id/batch` | 批次產生 `{ n }` → `{ count, ids:[...], decoded:[...] }` |
| GET | `/api/decode/{id}` | 解碼 → `{ id, timestamp, datetime, machineId, seq }` |

## API 範例

```bash
# 產生一個
curl -X POST localhost:8023/api/id

# 一次產生 50 個（觀察 seq 遞增）
curl -X POST localhost:8023/api/id/batch -H "Content-Type: application/json" -d '{"n":50}'

# 解碼
curl localhost:8023/api/decode/319639026040573952
```

範例輸出（解碼）：

```json
{
  "id": 319639026040573952,
  "timestamp": 1780275082414,
  "datetime": "2026-06-01 00:51:22.414 UTC",
  "machineId": 1,
  "seq": 0
}
```

## 64-bit 位元佈局

```
 0 | 41 bits 時間戳(ms) | 10 bits 機器 id | 12 bits 序號
 ^   ^                    ^                 ^
sign 自訂 epoch 起的毫秒   0..1023           0..4095（同毫秒遞增）

id = ((ts − EPOCH) << 22) | (machineId << 12) | seq
EPOCH = 1704067200000  (2024-01-01 00:00:00 UTC)
```

- 最高位 sign=0 → ID 落在 **63-bit 正整數**範圍（最大 ~9.2×10¹⁸），PHP x64 的 64-bit `int` 不會溢位。
- 41 bit 時間戳從自訂 epoch 起算，可用 ~69 年。
- 10 bit 機器 id → 1024 台。12 bit 序號 → 單機每毫秒 4096 個。

## 檔案

```
src/Snowflake.php   核心：bit 佈局 / 同毫秒遞增 / 序號溢位自旋 / 時鐘回撥 / decode
public/index.php    路由 + 測試頁（含 bit 佈局示意、批次展示 seq 遞增）
data/               執行期狀態 snowflake_state.json（已 gitignore，自動建立）
```

## ⚠️ 誠實聲明

- **真實 Snowflake 是「單機行程內、記憶體中以 synchronized/CAS 推進」**的取號器，state（`lastTimestamp`、`sequence`）常駐記憶體、不落盤；單機每秒可發 ~400 萬個。
- 本 demo 因 PHP-CLI 內建伺服器**每個 HTTP 請求是獨立 process**，記憶體狀態不共享。為了仍能展示「跨請求嚴格單調遞增」，這裡把 `{last_ts, seq}` **持久化到 `data/snowflake_state.json` 並用 `flock(LOCK_EX)` 鎖**，模擬單機記憶體中的臨界區。這條檔案鎖路徑是教學替身，**正式環境不該每次取號都讀寫檔案**。
- `nextIds(n)` 批次只取一次鎖、在記憶體中連續推進——這才是最貼近真實 Snowflake 的路徑，也是測試頁能看到同毫秒 seq 連續遞增的原因。
- 時鐘回撥邏輯為真實可執行：小幅（≤5ms）回撥自旋等待時鐘追上；大幅回撥直接丟例外拒絕發號。
- 機器 id 在 demo 由環境變數提供；真實系統開機時向 **ZooKeeper/etcd** 註冊取得唯一 id（見 notes §6）。
- 純原生 PHP 8.4、零依賴，僅用 `json` 擴充；無 mbstring / gmp / openssl / sqlite。
