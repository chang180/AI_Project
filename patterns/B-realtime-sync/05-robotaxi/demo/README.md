# RoboTaxi 叫車 · PHP Demo

聚焦系統設計考點：**地理空間查附近（geohash + haversine）→ 最近 K 台候選 → 原子媒合（CAS 防並發重複指派）→ 車輛狀態機 → ETA / 動態加價**。
這不是 CRUD demo，重點是「即時位置查詢」與「並發下同一台車不被搶兩次」。

## 啟動

```bash
cd demo
php -S localhost:8005 -t public
```

開瀏覽器 <http://localhost:8005>。

## 操作流程

1. **註冊幾台司機**（不同座標，模擬路上車隊），例如：
   - `d1` 25.0420 / 121.5650
   - `d2` 25.0500 / 121.5700
   - `d3` 25.0410 / 121.5630
2. **乘客在某點叫車**（如 25.0415 / 121.5635）→ 看媒合到哪台、距離 / ETA / 加價，該車狀態轉為 `matched`。
3. **再叫一次同一點** → 驗證<strong>不會重複指派同一台</strong>（已 matched 的車被排除，改派下一台）。
4. **更新某台司機位置** → 重新查附近 / 叫車，觀察結果隨位置變化。
5. **完成行程** → 車輛釋放回 `available`，可再次被媒合。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/drivers` | 註冊司機 `{ id, lat, lng, plate? }` |
| POST | `/api/drivers/{id}/location` | 更新位置（模擬 GPS 串流）`{ lat, lng }` |
| GET | `/api/drivers/nearby` | 查附近可用車 `?lat=&lng=&radius_km=&k=` |
| POST | `/api/rides` | 叫車媒合 `{ rider_id, lat, lng }` |
| POST | `/api/rides/{id}/complete` | 完成行程、釋放車輛 |

## API 範例

```bash
# 註冊三台車
curl -X POST localhost:8005/api/drivers -d "id=d1&lat=25.0420&lng=121.5650&plate=AAA-1"
curl -X POST localhost:8005/api/drivers -d "id=d2&lat=25.0500&lng=121.5700&plate=BBB-2"
curl -X POST localhost:8005/api/drivers -d "id=d3&lat=25.0410&lng=121.5630&plate=CCC-3"

# 查附近可用車
curl "localhost:8005/api/drivers/nearby?lat=25.0415&lng=121.5635&radius_km=5&k=5"

# 叫車兩次：第一次媒合到最近車，第二次自動換下一台（不重複指派）
curl -X POST localhost:8005/api/rides -d "rider_id=u1&lat=25.0415&lng=121.5635"
curl -X POST localhost:8005/api/rides -d "rider_id=u2&lat=25.0415&lng=121.5635"

# 完成行程（釋放車）
curl -X POST localhost:8005/api/rides/<ride_id>/complete
```

## 檔案

```
src/GeoIndex.php   地理空間索引：geohash 分桶 + haversine 精算，查附近 / 最近 K 台可用車
src/Matcher.php    媒合引擎：候選逐一 CAS 原子指派（防並發重複指派）+ ETA + surge + 行程狀態機
src/Store.php      司機/行程狀態 JSON「DB」+ flock 互斥區內的條件更新（CAS）
public/index.php   路由 + 深色測試頁
data/              執行期產生（已 gitignore）
```

## ⚠️ 示意 vs 真實

| 項目 | 本 demo | 真實系統 |
|---|---|---|
| 地理索引 | 單機 geohash + haversine 全掃可用車 | 分散式 Redis GEO / S2 / QuadTree |
| 原子指派 | `flock` 互斥區內條件更新（CAS） | DB 條件更新 / Redis `SET NX` 分散式鎖 |
| 位置串流 | HTTP POST 更新（手動觸發） | WebSocket 長連線高頻上報 |
| ETA | 直線距離 ÷ 平均車速 | 路網路由引擎（OSRM / Valhalla）+ 即時路況 |
| 雙向追蹤 | 表格呈現狀態 | Pub/Sub 推播即時座標 |

**系統核心邏輯（查附近、最近 K 台、原子指派防重複、狀態機、加價）為真實可執行**；
要升級到生產級，只需把 `GeoIndex` 後端換成 Redis GEO、把 `Store::compareAndSetStatus` 的 `flock` 換成 Redis `SET NX` 或 DB 條件更新，呼叫端介面不變。
