# 地震預警系統 · PHP Demo

聚焦系統設計考點：**地理篩選（haversine 半徑查詢）→ fan-out on write → 投遞保證（冪等去重 / 指數退避重試 / DLQ）**。
刻意不做 CRUD 表單，而是重現「一個地震事件如何被毫秒級篩出收件人、再炸成大量推播」的核心流程。

## 啟動

```bash
cd demo
php -S localhost:8002 -t public
```

開瀏覽器 <http://localhost:8002>：

1. 按「⚡ 載入 8 台示範裝置」（散佈於台灣各地）。
2. 設定震央 lat/lng + 規模 + 影響半徑 → 按「🚨 觸發地震」。
3. 觀察：哪些裝置落在半徑內、各自距震央幾公里、S 波抵達秒數、投遞狀態（含重試次數與模擬延遲）。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET  | `/` | 測試頁 |
| POST | `/api/devices` | 註冊一台裝置 `{ lat, lng, label }` |
| POST | `/api/devices/seed` | 一鍵載入 8 台示範裝置 |
| POST | `/api/quake` | 觸發地震 `{ lat, lng, magnitude, radius_km }` → 篩選 + fan-out + 投遞 |

## API 範例

```bash
curl -X POST localhost:8002/api/devices/seed
curl -X POST localhost:8002/api/quake \
  -H 'Content-Type: application/json' \
  -d '{"lat":23.965,"lng":120.97,"magnitude":6.5,"radius_km":120}'
```

回傳含：受影響裝置數（fan-out）、各裝置距離 / ETA、投遞 summary（sent / deduped / dlq）。

## 檔案

```
src/GeoIndex.php           空間索引：haversine 半徑查詢 + geohash 粗篩鍵示意（真實對應 PostGIS / Redis GEO）
src/NotificationQueue.php  fan-out on write：把事件展開成逐裝置任務，帶 (event_id, device_id) 冪等鍵
src/Dispatcher.php         投遞器：at-least-once + 冪等去重 + 指數退避重試 + DLQ
src/Store.php              JSON 檔模擬 DB（裝置 / 事件 / 投遞紀錄）
public/index.php           路由 + 深色測試頁
data/                      執行期產生（已 gitignore）
```

## 示意 vs 真實邏輯

| 環節 | 本 demo | 真實系統 |
|---|---|---|
| 偵測 | 手動觸發一次事件 | 感測器網路 + 信號處理估震央/規模 |
| 地理篩選 | haversine 對全體細篩（資料量小）；附 geohash 粗篩鍵示意 | geohash/PostGIS 粗篩候選集 + 細篩，帶 8 鄰格保 recall |
| fan-out | 記憶體佇列單機展開 | Kafka/SQS 依 region 分區並行 |
| 推播 | 模擬投遞（隨機延遲 / ~15% 失敗） | APNs / FCM / 簡訊 / 廣播多通道 |
| 冪等 / 重試 / DLQ | **真實可執行** | 同等邏輯，分散式落地 |

要接真實推播：把 `Dispatcher::push()` 的模擬邏輯換成 APNs/FCM SDK 呼叫即可，**地理篩選 / fan-out / 冪等去重 / 退避重試 / DLQ 邏輯完全不變**。
