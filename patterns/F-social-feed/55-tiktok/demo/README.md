# TikTok / Netflix「For You」推薦流 · PHP Demo

聚焦系統設計考點（**綜合題**：YouTube 轉碼分發 + 推薦排序 + Feed 分頁）：
**召回 → 排序 → 去重 → Top-N**，看一支 → **回饋迴路**更新興趣 → 下次推得更準。不是 CRUD。

- **召回（candidate generation）**：多路撈候選 — 興趣路（標籤相符）＋ 熱門路（全站熱門，也是冷啟動後備）＋ 協同路（看過同片的人也愛看，這裡用標籤共現近似）。
- **排序（ranking）**：對候選用特徵打分 = 相符度 × w1 ＋ 熱度 × w2 ＋ 新鮮度 × w3（真實系統是 ML 模型，這裡用可解釋的加權線性分數模擬）。
- **去重**：剔除「本輪 session 已看過的」→ 取下一頁不重複。
- **探索 vs 利用**：每頁保留少量名額給「沒接觸過的標籤 / 新片」，避免同溫層、給新內容曝光。
- **預載下一頁**：回一頁同時算好下一頁，前端可先抓，捲到底瞬間顯示（降延遲）。
- **回饋迴路**：看完 / 讚 → 把影片標籤加進使用者興趣向量 → 下次召回更貼、排序分更高。

## 啟動

```bash
cd demo
php -S localhost:8055 -t public
```

開瀏覽器 <http://localhost:8055>：按「① 載入示範影片」→ 取 `alice` 的 For You（dance/music 排前面）→
對某支按「看完 / 讚」→ 再取 For You，看那支被去重、同類分數變更高（更貼興趣）。換 `bob` 看冷啟動。

## 操作流程

1. 載入示範資料：12 支影片；`alice` 對 dance/music 有興趣；`bob` 無任何興趣訊號（冷啟動）。
2. 取 `alice` For You：召回（興趣/熱門/協同三路）→ 排序（相符度為主）→ 去重 → 一頁 5 支，並附預載的下一頁。
3. 對某支按「看完 / 讚」：記為已看（去重）＋ 影片熱度 +＋ 把標籤加進 `alice` 興趣（回饋迴路）。
4. 再取 For You：剛看的被**去重**不再出現；被強化的標籤類影片**分數更高**、排更前（更貼興趣）。
5. 取 `bob`：無興趣 → **冷啟動**靠熱門路 + 探索名額撐起第一頁。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（取頁 / 看影片 / 看狀態 一站式） |
| GET | `/api/feed?user=U` | 取一頁 For You（召回→排序→去重→Top-N + 預載下一頁） |
| POST | `/api/watch` | 看一支 `{ user, video, action }`（action: liked/completed/watched/skipped）→ 更新興趣 |
| GET | `/api/state?user=U` | 看使用者目前興趣向量 + 已看數 |
| POST | `/api/seed` | 一鍵建立示範影片 + 給 alice 初始興趣 |
| POST | `/api/reset?user=U` | 清掉本輪已看（重新開始一輪） |

## API 範例

```bash
curl -X POST localhost:8055/api/seed
curl "localhost:8055/api/feed?user=alice"
curl -X POST localhost:8055/api/watch -d "user=alice&video=v_cat1&action=liked"
curl "localhost:8055/api/feed?user=alice"   # v_cat1 不再出現，cat/pet 類分數上升
curl "localhost:8055/api/state?user=alice"
```

## 檔案

```
src/VideoStore.php    影片庫（標籤/熱度/新鮮度）+ 使用者興趣向量 + 本輪已看記錄
src/Recommender.php   核心：多路召回 → 特徵排序打分 → 去重 → 探索/利用 → Top-N + 預載下一頁
src/FeedSession.php   協調：取一頁 / 看一支（回饋迴路更新興趣與熱度）/ 看狀態 / 重新一輪
public/index.php      路由 + 深色測試頁（可視覺化分數、召回分路、探索與冷啟動）
data/                 執行期產生（已 gitignore）
```

## 對應真實元件

| Demo | 真實系統 |
|---|---|
| `videos.json` 中繼資料 | 影片中繼資料庫（分片）；影片本體經轉碼放物件儲存 / CDN |
| `users.json` interests | 使用者興趣 / 特徵向量（鍵值儲存 / 特徵庫，事件流即時更新） |
| `recall()` 多路 | 召回服務（倒排索引 / 向量近鄰 ANN / 協同過濾），離線 + 線上 |
| `score()` 加權線性 | 排序模型（GBDT / 深度模型，預估看完率 / 互動率） |
| `watched` 去重 | session 級去重 + 多樣性打散（去重服務 / Bloom filter） |
| `next_page` 預載 | 客戶端預取 + CDN 預熱下一批影片 |
| `watch()` 回饋 | 觀看 / 讚事件 → Kafka → 特徵更新 → 模型再訓練（回饋迴路） |

## ⚠️ 示意 vs 真實

- **示意**：用 JSON 檔模擬影片庫與使用者特徵；用**加權線性分數**模擬排序模型；無真影片、無真 ML 模型、無真轉碼/CDN。
- **真實邏輯**：多路召回、特徵排序打分、session 去重、探索 vs 利用、冷啟動後備、預載下一頁、看一支 → 興趣回饋迴路 → 推得更準，皆為真實可執行。

上正式環境：召回換成倒排索引 + 向量近鄰；排序換成 ML 模型服務；興趣更新走事件流（Kafka）；影片走轉碼 + CDN 分發。**召回→排序→去重→回饋的骨架不變**。
