# 分散式追蹤系統 · PHP Demo

聚焦系統設計考點：**由扁平 span 重建呼叫樹 → 算總時間 / 找最慢 span / 關鍵路徑 → head-based 取樣**。

## 啟動

```bash
cd demo
php -S localhost:8044 -t public
```

開瀏覽器 <http://localhost:8044>：頁面已內建一條跨 4 服務的 `/checkout` trace，
直接看到「呼叫樹 + 火焰圖長條 + 標出最慢 span + 關鍵路徑 + 取樣示範」。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（trace 樹、火焰圖、取樣） |
| POST | `/api/spans` | 批次收 span `{ spans:[...] }` → 回 `{accepted, traces}` |
| GET | `/api/trace/{trace_id}` | 回樹狀 + 總時間 + 最慢 span + 關鍵路徑 |
| POST | `/api/sample` | 取樣決策 `{ trace_id, rate }` → `{sampled, bucket, rate}` |
| POST | `/api/seed` | 重新寫入內建示範 span |

## span 欄位

```
trace_id        一整條請求鏈共用的 ID
span_id         單一工作單元 ID
parent_span_id  上一層 span（根為空字串）
service         服務名
name            操作名
start_ms        起始時間（毫秒）
duration_ms     耗時（毫秒）
```

## API 範例

```bash
# 收 span
curl -X POST localhost:8044/api/spans -H "Content-Type: application/json" \
  -d '{"spans":[{"trace_id":"t1","span_id":"a","service":"web","name":"GET /","start_ms":0,"duration_ms":50},
                {"trace_id":"t1","span_id":"b","parent_span_id":"a","service":"db","name":"SELECT","start_ms":10,"duration_ms":35}]}'

# 查 trace 樹（含最慢 span、關鍵路徑）
curl localhost:8044/api/trace/t1

# 取樣決策（1/10）
curl -X POST localhost:8044/api/sample -H "Content-Type: application/json" -d '{"trace_id":"t1","rate":10}'
```

## 檔案

```
src/TraceStore.php   重建呼叫樹 + 總時間/最慢 span/關鍵路徑 + head-based 取樣（flock 持久化）
public/index.php     路由 + 測試頁（火焰圖長條）
data/                執行期產生（已 gitignore）
```

## 核心邏輯說明

- **重建呼叫樹**：兩輪掃描——先把每個 span 建成節點，再依 `parent_span_id` 掛到父節點 `children`；
  父不在這批（取樣掉了）的孤兒 span 當成根接上，不讓樹斷掉。
- **關鍵路徑**：從根出發，每層挑「結束時間最晚」的子 span 一路往下；這串 span 決定整條 trace 何時完成，
  是「要優化哪裡」的依據——最慢的單一 span 不一定在關鍵路徑上（可能跟別的並行）。
- **head-based 取樣**：在 trace 開頭就用 `crc32(trace_id) % rate` 決定整條留不留，
  確保同一 trace 跨服務決策一致（不會半截）。對比 tail-based（看完整條再決定，能留住「慢/錯」的 trace，但要先全收）。

## ⚠️ 誠實聲明

JSON 檔模擬儲存；**trace 樹重建、總時間 / 最慢 span / 關鍵路徑、head-based 取樣為真實可執行邏輯**。
**未做**：真正的 OTLP 收集、context 跨網路傳播、tail-based 取樣的緩衝、列式 / 寬欄儲存與索引。
要接真系統，把這裡的 span 模型換成 OpenTelemetry SDK 產生、用 OTLP 上報到 Collector 即可，分析邏輯不變。
