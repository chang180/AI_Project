# CDN · PHP Demo

聚焦系統設計考點：**就近邊緣節點快取 → 命中(hit)/未命中(miss) → 回源 origin 依 Cache-Control TTL 存邊緣 → purge 失效 → 命中率統計**，並用**一致性雜湊**把資產對應到邊緣節點。

## 啟動

```bash
cd demo
php -S localhost:8051 -t public
```

開瀏覽器 <http://localhost:8051>：選資產 + 區域請求看 HIT/MISS，purge 後再請求變 MISS，下方看各邊緣命中率與快取內容。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| GET | `/api/fetch?asset=&region=` | 從某區域請求資產 → 回 HIT/MISS + 是否回源 + 歸屬節點 |
| POST | `/api/purge` | `{ asset }` 失效所有邊緣上的該資產 → 下次必 MISS |
| GET | `/api/stats` | 各區 hit/miss、總命中率、回源比、回源次數 |

## 體驗流程（面試核心）

```bash
# 第一次請求 → MISS（邊緣沒有 → 回源 origin 抓 → 依 TTL 存邊緣）
curl "localhost:8051/api/fetch?asset=/app.js&region=tw"     # result: MISS, served_from: origin
# 同區再請求 → HIT（邊緣已有且未過期）
curl "localhost:8051/api/fetch?asset=/app.js&region=tw"     # result: HIT,  served_from: edge
# purge → 失效所有邊緣
curl -X POST localhost:8051/api/purge -d '{"asset":"/app.js"}' -H "Content-Type: application/json"
# purge 後再請求 → 又 MISS（回源拿最新版本）
curl "localhost:8051/api/fetch?asset=/app.js&region=tw"     # result: MISS
# 命中率統計
curl localhost:8051/api/stats
```

## 檔案

```
src/HashRing.php   一致性雜湊環（虛擬節點）：資產 → 邊緣節點的穩定對應，加減節點時擾動最小
src/Origin.php     來源伺服器：回源抓資產 + 每資產的 Cache-Control TTL + 回源次數統計
src/Cdn.php        核心：就近選邊緣 → 邊緣 hit/miss → 回源依 TTL 存 → purge 失效 → 命中率統計（flock 原子）
public/index.php   路由 + 深色測試頁
data/              執行期產生（edges.json / origin.json / stats.json）
```

## 內建示範資產（不同 TTL）

| 資產 | Cache-Control TTL |
|---|---|
| `/logo.png` | 86400s（圖片可長快取） |
| `/app.js` `/style.css` | 3600s |
| `/index.html` | 30s |
| `/api/config.json` | 10s（接近即時的內容短 TTL） |

## ⚠️ 誠實聲明

邊緣節點為**記憶體模擬**（JSON 檔 + flock 原子讀-改-寫）；**hit/miss 判定、回源、TTL 過期、purge 失效、就近路由、一致性雜湊、命中率統計皆為真實邏輯**。要上線只需把 JSON 檔換成各 PoP 的本地快取（如 Varnish / nginx）、把 origin 換成真實物件儲存、把就近路由換成 anycast/GeoDNS——演算法邏輯不變。
