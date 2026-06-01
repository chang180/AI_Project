# 遊戲排行榜 Leaderboard · PHP Demo

聚焦系統設計考點：**ZSET 語意（zadd 更新即重排）→ Top-K → 我的名次±鄰居 → 同分 tie-break → 日/週/總分窗榜**。

## 啟動

```bash
cd demo
php -S localhost:8029 -t public
```

開瀏覽器 <http://localhost:8029>：提交分數、看 Top-K、輸入玩家查名次+鄰居、切換日/週/總榜。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（提交分數 / Top-K / 我的名次±鄰居 / 切換榜別） |
| POST | `/api/score` | 提交或更新分數 `{ member, score, board }` → 回更新後名次 |
| GET | `/api/top?k=&board=` | Top-K（分數由高到低） |
| GET | `/api/around/{member}?n=&board=` | 我的名次 + 上下各 n 名鄰居視窗 |

`board` 白名單：`daily`｜`weekly`｜`all`（各自是不同的 sorted set / 不同 key）。

## API 範例

```bash
# 提交分數（同名再次提交＝更新並重排）
curl -X POST localhost:8029/api/score -d "member=alice&score=1000&board=all"
curl -X POST localhost:8029/api/score -d "member=bob&score=1500&board=all"

# Top-K
curl "localhost:8029/api/top?k=5&board=all"

# 我的名次 + 前後各 2 名
curl "localhost:8029/api/around/alice?n=2&board=all"
```

## 檔案

```
src/SortedSet.php   模擬 Redis ZSET：zadd/zscore/zrevrank/topK/around + 同分 tie-break + 分窗榜，flock 保護、落地 data/
public/index.php    路由 + 測試頁（Top-K、名次±鄰居、日/週/總榜切換）
data/               執行期產生（已 gitignore）；每個榜一檔 zset_<board>.json
```

## 設計約定

- **排名 1 起算**：第 1 名 `rank = 1`（Redis 原生 zrevrank 由 0 起算，本 demo 為貼近玩家直覺改為 1 起算）。
- **同分 tie-break**：分數相同時「先加入者排前面」（用單調遞增 seq）。Redis 實際以 member 字典序，差異已於程式碼註明。
- **更新即重排**：`zadd` 同名只留一筆，更新分數後整體重排（呈現 ZSET 更新語意）。

## ⚠️ 誠實聲明：這是 ZSET 的「語意」模擬，不是 skiplist

真實 Redis ZSET 內部用 **skiplist + hash table**，`zadd` / `zrevrank` / `zrange` 都是 **O(log N)**。
本 demo 為零依賴，改用「PHP 陣列 + 每次排序」，複雜度退化為 **O(N log N)**：

- ✅ 為真：ZSET 語意、Top-K、我的名次±鄰居、同分 tie-break、日/週/總分窗榜、更新即重排。
- ❌ 簡化：未達 O(log N)；無真正 skiplist；持久化用 JSON 檔（flock 保護）而非 Redis。

要換成真正的 Redis，把 `SortedSet` 的方法一對一映射即可（語意完全相同）：

```
zadd(member, score)      → ZADD   <board> <score> <member>
zscore(member)           → ZSCORE <board> <member>
zrevrank(member)         → ZREVRANK <board> <member>
topK(k)                  → ZREVRANGE <board> 0 k-1 WITHSCORES
around(member, n)        → 先 ZREVRANK 取 r，再 ZREVRANGE <board> r-n r+n WITHSCORES
日/週榜過期              → 各榜 key 加 EXPIRE，或以時間切 key（如 lb:daily:20260601）
```
