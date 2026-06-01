# 地圖路徑規劃 (Google Maps) · PHP Demo

聚焦系統設計考點：**道路圖 → Dijkstra / A* 最短路 → A* 比 Dijkstra 探索更少節點 → 即時路況改 ETA → 地圖瓦片**。

## 啟動

```bash
cd demo
php -S localhost:8039 -t public
```

開瀏覽器 <http://localhost:8039>：選起點/終點 → 比較 Dijkstra 與 A* 的**探索節點數**；
對路徑上某段套用壅塞 → 看最快路徑改道、ETA 變化。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（選起訖、比較演算法、調路況） |
| POST | `/api/graph` | 以 `{ nodes:[{id,lat,lng}], edges:[{a,b,speed}] }` 重建道路圖 |
| GET | `/api/route?from=&to=&algo=&metric=` | 單次路由（`algo=dijkstra\|astar`、`metric=time\|distance`） |
| GET | `/api/compare?from=&to=&metric=` | 同時跑兩演算法並比較探索節點數 |
| POST | `/api/traffic` | 套用即時路況 `{ items:[{a,b,factor}], reset:bool }` |
| GET | `/api/tile?z=&x=&y=` | 地圖瓦片：回該瓦片框內的節點/邊（slippy map 概念） |
| GET | `/api/state` | 目前圖狀態（節點、邊、路況） |

## API 範例

```bash
# A* 與 Dijkstra 比較探索節點數（選格網中段終點差異最明顯）
curl "localhost:8039/api/compare?from=1&to=15&metric=time"

# 單次最快路徑
curl "localhost:8039/api/route?from=1&to=30&algo=astar&metric=time"

# 套用壅塞 → 重查看 ETA / 改道
curl -X POST localhost:8039/api/traffic -H "Content-Type: application/json" \
     -d '{"items":[{"a":7,"b":13,"factor":4}]}'

# 地圖瓦片
curl "localhost:8039/api/tile?z=14&x=13722&y=7014"
```

## 檔案

```
src/Graph.php     道路圖：節點(座標)+邊(distance/time/congestion)、haversine、瓦片切分
src/Router.php    Dijkstra 與 A*（共用二元堆積 MinHeap），各自回報探索節點數
public/index.php  路由 + 互動測試頁（SVG 畫圖、比較、調路況）
data/             執行期產生（已 gitignore），首次啟動自動塞 6×5 範例城市
```

## ⚠️ 誠實聲明

- **真實可執行**：Dijkstra、A*（含探索節點數比較）、即時路況改 ETA、最快路徑改道、瓦片切分。
- **僅觀念說明（未實作）**：Contraction Hierarchies / CRP（見 `notes.html` §6）。在這種小圖上 CH 不會帶來可感差異，它的價值要在大陸級圖才顯現。
- A* 找到的路徑與成本與 Dijkstra **相同（皆最優）**，差別只在「探索的節點數」。
  選格網**中段**的終點時 A* 少探索最明顯；**角落對角**是 A* 的最壞情況（幾乎所有節點都會被展開）。
```bash
# 例：13→18 距離度量，A* 只探索 6 個節點，Dijkstra 探索 20 個（省 70%）
curl "localhost:8039/api/compare?from=13&to=18&metric=distance"
```
