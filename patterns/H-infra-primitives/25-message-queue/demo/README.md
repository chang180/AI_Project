# 分散式訊息佇列 Kafka · PHP Demo

聚焦系統設計考點：**依 key 分區（crc32(key) % N）→ append-only log + 單調 offset → 消費者組 commit offset → replay 重放 → rebalance 分區指派**。

## 啟動

```bash
cd demo
php -S localhost:8025 -t public
```

開瀏覽器 <http://localhost:8025>。先 produce 幾筆（會自動建 3 分區的 `orders`），再 consume 看 offset 前進、replay 重放、看 rebalance 指派表。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/produce` | `{ topic, key, value, partitions? }` → 依 key 路由，回落點 partition / offset |
| POST | `/api/consume` | `{ group, topic }` → poll committed offset 之後的新訊息並 commit |
| POST | `/api/seek` | `{ group, topic, to }` → replay：把該 group 的 offset seek 回 `to`（預設 0） |
| GET | `/api/assign` | `?topic=&consumers=c1,c2&strategy=range\|roundrobin` → rebalance 指派表 |
| GET | `/api/status` | `?group=&topic=` → 各分區 committed / logEnd / lag |

## API 範例

```bash
# 同一 key 連 produce 三次 → 落同一分區、offset 0,1,2
curl -X POST localhost:8025/api/produce -H "Content-Type: application/json" \
  -d '{"topic":"orders","partitions":3,"key":"user-42","value":"order #1"}'
curl -X POST localhost:8025/api/produce -H "Content-Type: application/json" \
  -d '{"topic":"orders","key":"user-42","value":"order #2"}'

# 消費（poll + commit）
curl -X POST localhost:8025/api/consume -H "Content-Type: application/json" \
  -d '{"group":"billing","topic":"orders"}'

# 重放：seek 回 0，下次 consume 會重新讀到全部
curl -X POST localhost:8025/api/seek -H "Content-Type: application/json" \
  -d '{"group":"billing","topic":"orders","to":0}'

# rebalance 指派（3 分區、2 consumer、range → c1:[0,1] c2:[2]）
curl "localhost:8025/api/assign?topic=orders&consumers=c1,c2&strategy=range"
```

## 檔案

```
src/Broker.php      核心：createTopic / produce(crc32 key 路由 + offset) /
                    poll / commit / seek(replay) / assign(range,roundrobin) / groupStatus(lag)
public/index.php    路由 + 測試頁
data/               執行期產生（已 gitignore）：
                      topics.json            topic → 分區數
                      log_<topic>_p<N>.ndjson 每分區 append-only log
                      offsets.json           (group|topic|partition) → committed offset
```

## ⚠️ 誠實聲明：這是單機教學版

用「每分區一個檔案」模擬 append-only log，**沒有**副本(ISR)、網路協定、ZooKeeper/KRaft 協調或真正的 broker 叢集。

但**分區與 key 路由、單調 offset、分區內有序、消費者組 committed offset、poll/commit、replay(seek)、rebalance 指派**等核心邏輯**皆為真實可執行**——這些正是面試考點。所有寫入用 `flock` 保護，支援多請求併發。
