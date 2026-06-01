# 外送平台（DoorDash / Uber Eats）· PHP Demo

聚焦系統設計考點：**三邊市場（食客 / 餐廳 / 外送員）→ geohash 地理派單（找餐廳附近最近可用外送員）→ CAS 原子指派（防同一外送員被派兩單）→ 訂單生命週期狀態機 → 隨外送員位置變化的即時 ETA**。
這是 RoboTaxi ⑤（地理媒合 + 追蹤）的綜合延伸：**比叫車多了「餐廳備餐」這一環**，所以是三邊媒合 + 兩段距離（外送員→餐廳→食客）。

## 啟動

```bash
cd demo
php -S localhost:8057 -t public
```

開瀏覽器 <http://localhost:8057>。

## 操作流程

1. **外送員上線報位置**（不同座標，模擬路上外送員），例如：
   - `c1` 25.0500 / 121.5700
   - `c2` 25.0420 / 121.5655
   - `c3` 25.0410 / 121.5630
2. **食客下單**：填餐廳座標 + 送達座標 → 訂單狀態 `placed`。
3. **餐廳接單 / 備餐**：在追蹤面板把訂單推進 `placed → accepted → preparing`。
4. **派單**：對 `accepted/preparing` 的訂單按「派最近外送員」→ 在餐廳附近找最近可用外送員、CAS 原子指派、算 ETA。
5. **再派一張同餐廳的單** → 驗證**不會派到同一個外送員**（已 assigned 的被排除，改派下一位）。
6. **推進狀態**：`preparing → picked_up → delivered`，送達後外送員釋放回 `available`。
7. **追蹤**：移動外送員位置（模擬 GPS 串流），看**即時 ETA 隨之變化**。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/order` | 食客下單 `{ eater_id, restaurant, rest_lat, rest_lng, dest_lat, dest_lng }` |
| POST | `/api/courier/location` | 外送員上線 / 更新位置（模擬 GPS 串流）`{ id, lat, lng, name? }` |
| POST | `/api/dispatch` | 派單給最近可用外送員（地理媒合 + CAS）`{ order_id }` |
| POST | `/api/order/advance` | 推進訂單狀態（狀態機）`{ order_id }` |
| GET | `/api/track/{id}` | 訂單追蹤 + 即時 ETA |

## API 範例

```bash
# 三個外送員上線報位置
curl -X POST localhost:8057/api/courier/location -d "id=c1&name=Amy&lat=25.0500&lng=121.5700"
curl -X POST localhost:8057/api/courier/location -d "id=c2&name=Ben&lat=25.0420&lng=121.5655"
curl -X POST localhost:8057/api/courier/location -d "id=c3&name=Cat&lat=25.0410&lng=121.5630"

# 食客下單（取回 order_id）
curl -X POST localhost:8057/api/order \
  -d "eater_id=u1&restaurant=鼎泰豐&rest_lat=25.0418&rest_lng=121.5654&dest_lat=25.0480&dest_lng=121.5700"

# 餐廳接單 + 備餐（推進兩次：placed->accepted->preparing）
curl -X POST localhost:8057/api/order/advance -d "order_id=<order_id>"
curl -X POST localhost:8057/api/order/advance -d "order_id=<order_id>"

# 派最近可用外送員（地理媒合 + 原子指派）
curl -X POST localhost:8057/api/dispatch -d "order_id=<order_id>"

# 取餐 + 送達（再推進兩次：preparing->picked_up->delivered，送達後釋放外送員）
curl -X POST localhost:8057/api/order/advance -d "order_id=<order_id>"
curl -X POST localhost:8057/api/order/advance -d "order_id=<order_id>"

# 追蹤 + 即時 ETA
curl localhost:8057/api/track/<order_id>
```

## 檔案

```
src/GeoIndex.php     地理空間索引：geohash 分桶 + haversine 精算，找餐廳附近 / 最近 K 個可用外送員
src/Dispatcher.php   派單引擎 + 訂單狀態機：候選逐一 CAS 原子指派（防重複）+ 兩段 ETA + 即時追蹤 ETA
src/Store.php        訂單 / 外送員狀態 JSON「DB」+ flock 互斥區內的條件更新（CAS）+ 狀態原子推進
public/index.php     路由 + 深色測試頁
data/                執行期產生（已 gitignore）
```

## ⚠️ 示意 vs 真實

| 項目 | 本 demo | 真實系統 |
|---|---|---|
| 地理索引 | 單機 geohash + haversine 全掃可用外送員 | 分散式 Redis GEO / S2 / QuadTree |
| 原子指派 | `flock` 互斥區內條件更新（CAS） | DB 條件更新 / Redis `SET NX` 分散式鎖 |
| 位置串流 | HTTP POST 更新（手動觸發） | WebSocket 長連線高頻上報 |
| 訂單狀態事件 | 直接寫 JSON | 事件溯源 / 訊息佇列推進狀態機 |
| ETA | 備餐時間 + 兩段直線距離 ÷ 平均車速 | 路網路由引擎（OSRM / Valhalla）+ 即時路況 + 備餐預估模型 |
| 派單策略 | 貪婪挑最近 | 全域最佳化 / 順路批量指派 / 供需定價 |

**系統核心邏輯（地理找附近、最近 K 個、CAS 原子指派防重複、訂單狀態機、隨位置變化的即時 ETA）為真實可執行**；
要升級到生產級，只需把 `GeoIndex` 後端換成 Redis GEO、把 `Store::compareAndSetStatus` 的 `flock` 換成 Redis `SET NX` 或 DB 條件更新，呼叫端介面不變。
