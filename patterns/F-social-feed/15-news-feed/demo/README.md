# News Feed 動態牆 · PHP Demo

聚焦系統設計考點：**推 / 拉 / hybrid fan-out 取捨**，不是 CRUD。

- 一般使用者發文 → **fan-out on write（推）**：把 post_id 寫進每個粉絲的 feed 快取。
- 名人（粉絲數 ≥ 門檻）發文 → **不推**，改在讀時 **fan-out on read（拉）**，避免一次發文寫爆千萬份 feed。
- 讀 feed = **hybrid**：合併「推來的」+「即時拉名人的」，依時間（seq）排序，每筆標記 source。

## 啟動

```bash
cd demo
php -S localhost:8015 -t public
```

開瀏覽器 <http://localhost:8015>：按「① 一鍵載入示範資料」→ 看 `alice` 的 feed 中，
哪些是「推來的」（一般使用者 bob / carol）、哪些是「即時拉」（名人 star）。

## 操作流程

1. 載入示範資料：`star` 被 4 人追蹤（≥ 門檻 → 名人 → 拉）；`bob` / `carol` 一般使用者（→ 推）。
2. 多人發文：`bob` / `carol` 發文會即時推進 `alice` 的 feed 快取；`star` 發文不推。
3. 讀 `alice` feed：推來的（bob/carol）+ 即時拉的（star）合併、依時間排序，逐筆標來源。
4. 看下方表格：每位使用者依粉絲數 vs 門檻被判定為 **推 PUSH** 或 **拉 PULL**。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（操作 + 視覺化推/拉 + feed 來源） |
| POST | `/api/follow` | 建立追蹤 `{ follower, followee }` |
| POST | `/api/post` | 發文 `{ author, text }` → 回 fan-out 策略（push/pull） |
| GET | `/api/feed?user=U` | 讀某人 feed（hybrid，每筆含 `source`） |
| GET | `/api/plan` | 每位使用者被判定推 or 拉 |
| POST | `/api/seed` | 一鍵建立示範資料（含名人 `star`） |

## API 範例

```bash
curl -X POST localhost:8015/api/seed
curl -X POST localhost:8015/api/follow -d "follower=eve&followee=star"
curl -X POST localhost:8015/api/post   -d "author=bob&text=hello"
curl "localhost:8015/api/feed?user=alice"
curl localhost:8015/api/plan
```

## 檔案

```
src/SocialGraph.php   社交圖（follows）：追蹤/粉絲關係 + 名人門檻判定
src/FeedStore.php     貼文庫(posts) + 每使用者 feed 快取（推模型寫入處，只存 post_id）
src/FeedService.php   核心：發文推/拉判定、fan-out on write、讀時拉名人、k 路合併排序、來源標記
public/index.php      路由 + 深色測試頁
data/                 執行期產生（已 gitignore）
```

## 對應真實元件

| Demo | 真實系統 |
|---|---|
| `feeds.json` 每使用者列表 | Redis feed 快取列表（截斷最新 N 筆） |
| `posts.json` | 貼文庫（單一真相來源，分片 DB） |
| `follows.json` 雙向索引 | follows 分片表 + 二級索引 |
| `publish()` 同步推 | Post Service 寫庫 → Kafka → fan-out worker 非同步推 |
| `CELEBRITY_THRESHOLD` | 名人門檻（粉絲數 / 推一次成本動態判定） |

## ⚠️ 示意 vs 真實

- **示意**：用 JSON 檔模擬 DB 與 Redis；用同步函式呼叫模擬 Kafka 非同步 fan-out。
- **真實邏輯**：推/拉判定、fan-out on write 寫入、讀時即時拉名人、hybrid 合併排序、來源（push/pull）標記。

上正式環境只需把 `FeedStore` 換成 Redis、把 `publish()` 的 fan-out 改丟訊息佇列由背景 worker 消費，核心邏輯不變。
