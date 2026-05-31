# Spotify 趨勢榜 · PHP Demo

聚焦大規模 Top-K 真正的考點：**Count-Min Sketch 近似計數 → Top-K（Heavy Hitters）→ 指數時間衰減**。
不是 CRUD，而是「海量播放事件如何用固定記憶體估出即時排行」。

## 啟動

```bash
cd demo
php -S localhost:8006 -t public
```

開瀏覽器 <http://localhost:8006>：
1. 點「模擬一批播放」累積串流計數。
2. 查某歌的 **sketch 估計 vs 精確** 看近似誤差（估計值永遠 ≥ 真值）。
3. 看 **Top-K 榜**。
4. 點「套用時間衰減」，看舊熱門歌分數下滑、排名變化。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 深色測試頁 |
| POST | `/api/seed` | 模擬一批播放事件（長尾分布的不同熱度歌曲） |
| POST | `/api/play` | 手動餵某首歌 `{ song, n }` |
| GET | `/api/score?song=...` | sketch 估計 vs 精確計數的誤差對比 |
| GET | `/api/trending` | 目前 Top-K 榜（含 estimate / exact） |
| POST | `/api/decay` | 套用一次時間衰減 `{ lambda }`（0<λ<1） |
| POST | `/api/reset` | 清空狀態 |

## API 範例

```bash
curl -X POST localhost:8006/api/seed
curl "localhost:8006/api/score?song=s_levitating"
curl localhost:8006/api/trending
curl -X POST localhost:8006/api/decay -H "Content-Type: application/json" -d '{"lambda":0.8}'
```

## 檔案

```
src/CountMinSketch.php  真實 Count-Min Sketch：d 列雜湊 × w 寬，add/estimate(取min)/decay；記憶體固定 O(w·d)
src/TopK.php            Heavy Hitters：用 sketch 估頻率 + 大小 K 的候選集合(heap)，支援每輪指數衰減
src/EventStore.php      JSON 檔保存串流算子狀態（sketch / 候選 / 精確計數 / 事件數），跨請求延續
public/index.php        路由 + 深色測試頁
data/                   執行期產生（已 gitignore）
```

## ⚠️ 示意 vs 真實

- **示意**：單機單行程，用 JSON 檔保存串流算子狀態，並非真的接 Kafka / Flink 叢集；
  另存一份「精確計數」純粹是為了在頁面上對比誤差，真實系統不會留。
- **真實**：Count-Min Sketch 的雜湊計數與取 min 估計、Top-K 的 heap 維護、指數時間衰減
  **皆為真實可執行的演算法**。多節點環境下，相同 w/d/雜湊的 sketch 可逐格相加直接合併聚合。

要換成精確的分桶滑動視窗，只需把單一 sketch 換成「每分鐘一份 sketch」的環形緩衝、查視窗時相加即可，演算法骨架不變。
