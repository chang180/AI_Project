# Tinder / 配對系統 · PHP Demo

聚焦系統設計考點：**geohash 找附近的人（召回）→ 雙向偏好過濾（性別/年齡/距離）→ 去重（看過不再出現）→ 排序 → 滑卡 → 雙向 like 觸發配對**。
組合「附近的人 ⑱ geohash」+「推薦 ㊸ 召回/排序」兩個考點。不是 CRUD 練習，重點在「配對核心」。

## 啟動

```bash
cd demo
php -S localhost:8058 -t public
```

開瀏覽器 <http://localhost:8058>，先按「塞入示範使用者」（台北市區 8 位），再選一位使用者看候選堆疊並滑卡。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（深色） |
| POST | `/api/seed` | 一鍵塞入示範使用者 |
| GET | `/api/candidates?user=` | 取候選堆疊（geohash 召回 + 偏好過濾 + 去重 + 排序） |
| POST | `/api/swipe` | 滑卡 `{ from, to, action: like\|pass }`；雙向 like 觸發 match |
| GET | `/api/matches?user=` | 某使用者的所有配對 |

## API 範例

```bash
# 塞示範資料
curl -X POST localhost:8058/api/seed

# 取 Alice 的候選堆疊
curl "localhost:8058/api/candidates?user=u_alice"

# Alice like Bob（單向，尚未配對）
curl -X POST -H "Content-Type: application/json" \
  -d '{"from":"u_alice","to":"u_bob","action":"like"}' localhost:8058/api/swipe

# Bob like Alice（雙向 → 立即配對 matched:true）
curl -X POST -H "Content-Type: application/json" \
  -d '{"from":"u_bob","to":"u_alice","action":"like"}' localhost:8058/api/swipe

# 看 Alice 的配對
curl "localhost:8058/api/matches?user=u_alice"
```

## 檔案

```
src/GeoHash.php       經緯度 ↔ base32 geohash + neighbors()（中心格 + 8 鄰格）+ haversine
src/Store.php         使用者 / 滑卡(高頻 append) / 配對 / 已看集合（JSON 檔 + flock 模擬 DB）
src/MatchEngine.php   候選產生（召回→雙向偏好過濾→去重→排序）+ 配對偵測（互相 like）
public/index.php      路由 + 深色測試頁
data/                 執行期產生（已 gitignore）
```

## 核心邏輯

### 1. 候選產生（呼應推薦系統「召回 → 過濾 → 排序」）

1. **召回**：用查詢者位置的 geohash 取「中心格 + 8 鄰格」共 9 桶，撈出地理就近的人。
2. **雙向偏好過濾**：我要看的（對方性別/年齡/距離合我意）**且**對方也願意看我（我合對方偏好）——避免無效曝光。
3. **去重**：看過 / 已滑過的對象不再出現（由 swipes 即時推導，呼應爬蟲去重）。
4. **排序**：「對方已 like 過我」者排最前（互相喜歡機率最高），其次距離近者優先 → 提升配對率。

### 2. 配對偵測（互相 like）

滑卡記錄為 `(from → to, like|pass)`。當 A like B 時，檢查 swipes 裡是否已存在「B → A 且 like」：
- 沒有 → 只記錄，不配對。
- 有 → 雙向成立 → 寫入一筆 `match`（用排序後的 `pair key` 去重，A-B 與 B-A 視為同一對）。

### 3. 去重 / 冪等

- **候選去重**：`seenSet(user)` = 凡 `from=user` 的所有 `to`，候選排除之。
- **滑卡冪等**：同一對 `(from→to)` 已滑過則不重覆記錄。

## ⚠️ 示意 vs 真實

- **真實可執行的系統邏輯**：geohash 編解碼 / 鄰格計算 / 前綴分桶（候選召回）、雙向偏好過濾、haversine 距離、配對偵測（互相 like）、已看去重、提升配對率的排序。
- **示意簡化**：使用者位置與滑卡為內建示範資料；「DB」用 JSON 檔（`flock` 原子寫）、索引每次請求由資料重建（真實系統候選召回常駐 Redis GEO / 向量 ANN，已看集合用 bloom filter / Redis set）。
- 本機 PHP **未載入 mbstring**，程式全程只用原生字串函式（id / 性別皆 ASCII，安全）。

要換成生產級（Redis `GEOSEARCH` 召回 + 雙塔向量 ANN + 排序模型 + bloom 去重），
只需替換 `MatchEngine` / `Store` 內部實作，`candidates()` / `swipe()` 介面與呼叫端不變。
