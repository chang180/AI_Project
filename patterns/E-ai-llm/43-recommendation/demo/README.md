# 推薦系統（召回 + 排序）· PHP Demo

聚焦系統設計考點：**兩階段架構 — 召回 candidate generation（協同過濾 + Embedding ANN）→ 排序 ranking（多特徵加權 Top-N）**，外加**冷啟動**熱門 fallback。

## 啟動

```bash
cd demo
php -S localhost:8043 -t public
```

開瀏覽器 <http://localhost:8043>：
- 上方列出預設 user 與其互動，點 user 即可看「召回候選 → 排序 Top-N」兩階段。
- 在「看推薦的 user」欄輸入一個沒互動過的新名字（如 `newbie`）→ 看**冷啟動**退回熱門。
- 用下方表單新增互動（view/click/buy）→ 召回、排序與熱門度會即時改變（回饋迴路）。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（user/互動、兩階段推薦、冷啟動） |
| POST | `/api/interaction` | 新增互動 `{ user, item, type }`（type ∈ view/click/buy） |
| GET | `/api/recommend?user=` | 對某 user 取推薦，回傳召回候選 + 排序明細 + Top-N |
| GET | `/api/state` | 傾印 item / 互動事件 / item-item 相似度矩陣 |

## API 範例

```bash
# 有互動的 user → 兩階段（召回→排序）
curl "localhost:8043/api/recommend?user=u1"

# 新 user → 冷啟動熱門
curl "localhost:8043/api/recommend?user=newbie"

# 新增一筆互動
curl -X POST localhost:8043/api/interaction \
  -H "Content-Type: application/json" \
  -d '{"user":"u1","item":"i5","type":"buy"}'
```

## 兩階段流程（看 `/api/recommend` 回傳）

```
召回 recall
  ├─ cf   ：協同過濾（item-item cosine 共現）撈出的候選 + 召回分
  └─ ann  ：embedding 內積最近鄰撈出的候選
  → merged：兩路聯集、且排除使用者已互動過的 item
排序 ranking
  → 對 merged 候選用 4 個特徵加權打分：
     score = 0.45·召回分 + 0.30·與user相似度 + 0.15·熱門度 + 0.10·新鮮度
  → topN：取前 N
```

## 檔案

```
src/Store.php        互動記錄 + item 中繼資料/特徵（JSON 檔，flock 保護寫入）；預設塞 5 user × 多筆互動
src/Recommender.php  兩階段核心：item-item 相似度、CF 召回、Embedding ANN 召回、多特徵排序、冷啟動 fallback
public/index.php     路由 + 深色測試頁（呈現召回候選與排序特徵明細）
data/                執行期產生（已 gitignore）
```

## ⚠️ 誠實聲明

- **真實可執行**：協同過濾（user-item → item-item cosine）、兩階段召回排序、多特徵打分、冷啟動熱門 fallback、互動回饋更新熱門度。
- **示意簡化**：item embedding 用 **category one-hot** 代替（真實系統為訓練出的稠密向量）；ANN 用**暴力全掃**（真實系統用 HNSW / IVF 索引把 O(N) 降到近似 O(log N)）；排序為**線性加權**（真實系統用 GBDT / 深度排序模型）。
- 要接真模型：把 `itemEmbeddings()` 換成向量庫查詢、把 `rank()` 的線性加權換成排序模型推論即可，**兩階段框架與冷啟動邏輯完全不變**。

> 觀察重點：`u3` 只買過家具且家具 item 不多，CF 撈不出新候選，但 **Embedding ANN 仍能憑同類別撈出沒人一起買過的 `i9` 收納層架** — 這正是 ANN 補 CF 覆蓋不足的價值。
