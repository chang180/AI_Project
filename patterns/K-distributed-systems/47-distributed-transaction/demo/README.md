# 分散式交易 · PHP Demo（兩階段提交 2PC）

聚焦系統設計考點：**協調者(TM) + 多個參與者(分片) → prepare 投票 → commit / abort → 原子性回滾**。

用「跨兩個分片轉帳」當例子：A 分片扣款、B 分片入帳，**要嘛都成功、要嘛都回滾**。

## 啟動

```bash
cd demo
php -S localhost:8047 -t public
```

開瀏覽器 <http://localhost:8047>。

## 怎麼玩（建議順序）

1. **正常轉帳**：alice(A) → bob(B) 轉 200。看分片日誌：兩邊都 `prepare → YES`、協調者決議 `COMMIT`、兩邊 `commit`。總額仍是 2000。
2. **注入失敗看回滾**：操作②把分片 B 設成 `prepare 投 NO`，再轉一次帳。看 A 投 YES、B 投 NO → 協調者決議 `ABORT` → A 收到 abort 把預寫丟棄回滾。**alice 的錢沒少、總額仍是 2000**（原子性）。
3. **餘額不足**：轉一筆超過 alice 餘額的金額，A 在 prepare 階段就投 NO → 整筆 abort。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（餘額、跑 2PC、注入失敗、決議日誌） |
| POST | `/api/transfer` | 跨分片轉帳，走 2PC。`{ from_shard, from_acc, to_shard, to_acc, amount }` |
| POST | `/api/fail` | 注入失敗。`{ shard, mode: ''｜'prepare'｜'commit' }` |
| POST | `/api/reset` | 重設帳戶（alice=1000, bob=1000） |
| GET | `/api/state` | 所有分片餘額、鎖定、總額、決議日誌（JSON） |

## API 範例

```bash
# 正常轉帳（2PC commit）
curl -X POST localhost:8047/api/transfer \
  -H 'Content-Type: application/json' \
  -d '{"from_shard":"A","from_acc":"alice","to_shard":"B","to_acc":"bob","amount":200}'

# 注入：分片 B 在 prepare 投 NO
curl -X POST localhost:8047/api/fail -H 'Content-Type: application/json' -d '{"shard":"B","mode":"prepare"}'

# 再轉一次 → 會 ABORT 回滾，總額不變
curl -X POST localhost:8047/api/transfer -H 'Content-Type: application/json' \
  -d '{"from_shard":"A","from_acc":"alice","to_shard":"B","to_acc":"bob","amount":200}'

curl localhost:8047/api/state
```

## 檔案

```
src/Coordinator.php   協調者(TM)：prepare 投票 → 全 YES commit / 任一 NO abort、決議落盤
src/Participant.php    參與者(分片 RM)：prepare 預寫+鎖定、commit 套用、abort 回滾（flock 原子化）
public/index.php       路由 + 測試頁
data/                  執行期產生（shard_*.json / coordinator_log.json，已 gitignore）
```

## ⚠️ 誠實聲明

這是**單一 PHP process 模擬**協調者 + 參與者：**沒有**真實網路 RPC、**沒有**多機。
但 **2PC 的 prepare/commit/abort 兩階段、預寫日誌(WAL)+鎖定、投票決議、原子回滾皆為真實可執行邏輯**——這正是本題核心考點。
要變成真叢集，把「每個參與者一個 JSON 檔」換成「每個參與者一支 RPC 服務」即可，2PC 演算法不變。

零依賴：僅用 `json + PDO/檔案 + flock`，每檔 `declare(strict_types=1);`。
