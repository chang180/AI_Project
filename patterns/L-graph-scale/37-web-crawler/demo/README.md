# 網頁爬蟲 Web Crawler · PHP Demo

聚焦系統設計考點：**BFS frontier → 抓取 → 抽 link → bloom filter 去重 → politeness/crawl-delay → 依 domain 分片的分散式 frontier → freshness 重抓排程**。

> 誠實聲明：本 demo **不連真網路**，用 process 內建的「假網路圖」（url → 內容 + 外連 links）做確定性 BFS 爬取，方便觀察與測試。
> 但 BFS、bloom 去重、politeness、分片、重抓排程、爬蟲陷阱深度限制等**爬蟲核心邏輯皆為真實可執行**。

## 啟動

```bash
cd demo
php -S localhost:8037 -t public
```

開瀏覽器 <http://localhost:8037>，先 **seed 載入假網路**，再反覆按 **crawl** 觀察：
frontier 逐步擴展、visited 成長、回連 URL 被去重擋掉、同 domain 連續抓被 politeness 延後、shard 指派固定。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/seed` | 載入假網路並設種子 `{ seeds?: string[] }` |
| POST | `/api/crawl` | 步進 N 步 `{ steps }` |
| GET | `/api/state` | 取得目前爬取狀態 |
| POST | `/api/reset` | 重置爬取狀態 |

## API 範例

```bash
# 重置 → 載入假網路（預設種子 http://news.example/home）
curl -X POST localhost:8037/api/reset -d '{}'
curl -X POST localhost:8037/api/seed -d '{}'
# 爬 5 步，看每步事件（fetched / deferred / dedup-skip / idle / frontier-empty）
curl -X POST localhost:8037/api/crawl -H "Content-Type: application/json" -d '{"steps":5}'
curl localhost:8037/api/state
```

## 檔案

```
src/BloomFilter.php  位元陣列 + k 雜湊（double hashing）；add/mightContain，含偽陽性率估算
src/Crawler.php      內建假網路圖 + BFS frontier + 去重 + politeness + 依 domain 分片 + 重抓排程
public/index.php     路由 + 測試頁（frontier/visited/bloom/politeness/shard 視覺化）
data/                執行期產生（已 gitignore）：crawler.json（flock 保護）
```

## 核心考點對照

| 元件 | demo 真實邏輯 | 真實系統對應 |
|---|---|---|
| Frontier | 分片佇列 + 輪詢取出（BFS） | Kafka / 優先佇列 + 分散式 frontier |
| URL 去重 | bloom（省記憶體）+ 精確 seen 二次確認 | 數十億 URL 的 bloom filter + 後端精確集合 |
| Politeness | per-domain `lastFetch` + crawl-delay | robots.txt crawl-delay、per-host token bucket |
| 分散式分片 | `crc32(domain) % N`（同 domain → 同 shard） | 依 host 一致性雜湊分片，politeness 狀態集中 |
| 重抓 | `fetchedAt + interval` 到期重排 | 依頁面變更頻率 / 重要性的 freshness 排程 |
| 爬蟲陷阱 | `maxDepth` 深度上限 | URL 正規化、深度/數量上限、陷阱偵測 |

## ⚠️ 為什麼用假網路圖，而非真的去抓網頁？

教學重點是**爬蟲的系統邏輯**（frontier 排程、去重、禮貌性、分片），不是 HTTP 抓取本身。
用 process 內的確定性假網路圖，可以：

- 不依賴外部網路、每次結果可重現、方便寫測試與觀察。
- 把焦點放在「百萬/十億級 URL 時真正的難點」：去重結構規模、熱門站集中、freshness。

要換成真實抓取，只需把 `Crawler::sampleWeb()` 換成「對 url 發 HTTP GET、解析 HTML 抽 `<a href>`」即可；
frontier / 去重 / politeness / 分片 / 重抓等邏輯完全不變。
