# 附近地點 / Proximity Service · PHP Demo

聚焦系統設計考點：**geohash 前綴分桶 → 中心格 + 8 鄰格粗篩 → haversine 精算排序 → 半徑 / 最近 K 查詢 + 屬性過濾 + 分頁**。
不是 CRUD 練習，重點在「靜態地點的空間搜尋索引」。

## 啟動

```bash
cd demo
php -S localhost:8018 -t public
```

開瀏覽器 <http://localhost:8018>，先按「塞入示範地點」（台北市區 12 筆），再做半徑 / 最近 K 查詢。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（深色） |
| POST | `/api/places` | 新增地點 `{ name, category, rating, lat, lng }` |
| POST | `/api/seed` | 一鍵塞入示範地點（台北市區） |
| GET | `/api/nearby` | 半徑查詢，依距離排序 |
| GET | `/api/nearest` | 最近 K 個 |
| GET | `/api/buckets` | 目前 geohash 桶摘要 |

### `/api/nearby` 參數

`lat` `lng` `radius`(km) `category`(可選) `min_rating`(可選) `offset` `limit`

回傳含 `center_geohash`、`buckets_scanned`（實際掃描的 9 個 geohash 桶，含 8 鄰格）、`candidates`（粗篩候選數）、`total_matched`、每筆 `distance_km`、`cache`（快取命中率）。

## API 範例

```bash
# 塞示範資料
curl -X POST localhost:8018/api/seed

# 從台北101半徑 2km，依距離排序
curl "localhost:8018/api/nearby?lat=25.0339&lng=121.5645&radius=2"

# 加上類別 + 最低評分過濾
curl "localhost:8018/api/nearby?lat=25.0339&lng=121.5645&radius=2&category=景點&min_rating=4.6"

# 最近 5 個
curl "localhost:8018/api/nearest?lat=25.0339&lng=121.5645&k=5"

# 看 geohash 桶
curl localhost:8018/api/buckets
```

## 檔案

```
src/GeoHash.php        經緯度 ↔ base32 geohash 編解碼 + neighbors()（中心格 + 8 鄰格）
src/SpatialIndex.php   geohash 前綴分桶；半徑查詢/最近K（9 桶粗篩 + haversine 精算 + 屬性過濾 + 分頁）
src/PlaceStore.php     地點資料（JSON 檔模擬 DB）+ 行內快取（讀多寫少）
public/index.php       路由 + 深色測試頁
data/                  執行期產生（已 gitignore）
```

## 核心邏輯：為何要查 9 個桶？

geohash 把連續座標離散成字串，**共同前綴越長 = 越接近**。但格子有邊界：
兩個物理上很近的點，可能剛好落在相鄰格子（前綴不同）。
若只查中心格，會漏掉貼著邊界的鄰近地點。

因此半徑查詢一律取「中心格 + 周圍 8 個鄰格」共 9 桶做**粗篩**（縮小範圍），
再用 **haversine 球面距離**精算、過濾半徑外者、依距離排序（細排）。
`/api/nearby` 回傳的 `buckets_scanned` 就是這 9 個桶，可直接觀察邊界處理。

## ⚠️ 示意 vs 真實

- **真實可執行的系統邏輯**：geohash 編解碼、鄰格計算、前綴分桶、半徑 / 最近 K 查詢、haversine 精算、類別 / 評分過濾、分頁、讀取快取命中統計。
- **示意簡化**：地點為內建示範靜態資料；「DB」用 JSON 檔、「快取」用 process-local 陣列；索引每次請求由資料重建（真實系統索引常駐記憶體或交給 Redis GEO / PostGIS / S2）。
- 本機 PHP **未載入 mbstring**，程式全程只用 `strlen` / `substr`（geohash 皆 ASCII，安全）。

要換成生產級空間索引（PostGIS `ST_DWithin` / Redis `GEOSEARCH` / Google S2），
只需替換 `SpatialIndex` 內部實作，`nearby()` / `nearestK()` 介面與呼叫端不變。
