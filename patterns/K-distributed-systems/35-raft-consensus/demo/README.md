# 共識演算法 Raft · PHP Demo

聚焦系統設計考點：**leader election（選舉逾時 + 投票限制）→ log replication（AppendEntries 一致性檢查/回退）→ commit 規則（多數 + 當前 term）→ apply 到複製狀態機 → 故障重新選舉**。

因 PHP 無跨請求常駐進程與背景計時器，採用**單一 process 內模擬 3~5 節點 + 手動 `tick()` 驅動邏輯時鐘**的「確定性模擬」：RPC 變成 process 內的函式呼叫，選舉逾時改用每節點倒數計時器（tick 一次減一）。**Raft 的選舉、投票、複製、commit、安全性規則皆為真實正確的實作。**

## 啟動

```bash
cd demo
php -S localhost:8035 -t public
```

開瀏覽器 <http://localhost:8035>。建議流程：

1. **reset**（3 或 5 節點）
2. **tick 數次** → follower 選舉逾時 → candidate → 多數投票 → **選出 leader**
3. **提交 2~3 個 command**（如 `x=1`、`cnt++`）→ 看複製到多數、`commitIndex` 前進、各節點狀態機 KV 一致
4. **kill 掉目前 leader**
5. 再 **tick 數次** → 存活多數**重新選出新 leader**，且**已 commit 的 log 不丟**

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁：每節點 role / term / log / commitIndex；按鈕 tick、提交 command、kill/revive、reset |
| POST | `/api/tick` | `{ steps }` 推進邏輯時鐘，回傳本批發生的事件（選舉/當選/複製/commit） |
| POST | `/api/command` | `{ command }` 提交指令（**只有 leader 接受**）；append 到 leader log，待多數複製後 commit |
| POST | `/api/node` | `{ id, alive }` kill（`alive=false`）或 revive（`alive=true`）節點 |
| GET | `/api/state` | 目前叢集狀態 |
| POST | `/api/reset` | `{ n }` 重置為 n 個全新 follower（n=3 或 5） |

## API 範例

```bash
H='-H Content-Type:application/json'

# 重置 3 節點
curl -s -X POST localhost:8035/api/reset $H -d '{"n":3}'

# tick 6 次 → 選出 leader（看回傳 events 與 state.leader）
curl -s -X POST localhost:8035/api/tick $H -d '{"steps":6}'

# 提交指令（只有 leader 接受）：x=1、計數器 cnt++
curl -s -X POST localhost:8035/api/command $H -d '{"command":"x=1"}'
curl -s -X POST localhost:8035/api/command $H -d '{"command":"cnt++"}'

# 再 tick 讓 commit 與 apply 推進，看各節點 commitIndex / kv 一致
curl -s -X POST localhost:8035/api/tick $H -d '{"steps":2}'

# kill 掉 leader（假設是 n1），再 tick → 重新選舉，已 commit log 不丟
curl -s -X POST localhost:8035/api/node $H -d '{"id":"n1","alive":false}'
curl -s -X POST localhost:8035/api/tick $H -d '{"steps":12}'
```

> 注意：以 `application/json` 送 body（如上 `$H`）；否則 PHP 會當成表單，數值欄位讀不到。

### 狀態機指令格式

apply 到複製狀態機（一個簡單 KV）的指令支援：

- `key=value` → 設值（如 `x=1`、`y=hello`）
- `key++` → 計數器遞增（如 `cnt++`）
- 其餘字串 → 存成 `_last`

重點是「**所有節點 apply 相同有序 log → 得到相同狀態**」（複製狀態機 RSM）。

## 檔案

```
src/RaftCluster.php  核心：tick(邏輯時鐘) / startElection(投票限制) /
                     handleRequestVote(選舉限制) / leaderReplicate + handleAppendEntries
                     (prevLogIndex/Term 一致性檢查 + 衝突截斷 + nextIndex 回退) /
                     advanceCommitIndex(多數 + 當前 term) / applyCommitted(RSM) /
                     setNodeAlive(kill/revive)
public/index.php     路由 + 測試頁（節點面板 + 操作按鈕 + 事件輸出）
data/                執行期產生（已 gitignore）：cluster.json（flock 保護，
                     對應 Raft「currentTerm/votedFor/log 需持久化」）
```

## ⚠️ 誠實聲明：單 process + 手動 tick 的確定性模擬

- **沒有**真實網路 RPC（RequestVote / AppendEntries 是 process 內函式呼叫）。
- **沒有**真正的背景隨機計時器（選舉逾時用邏輯時鐘確定性錯開，避免分票）；要靠手動 `tick()` 推進時間。
- 為了讓單次 `tick`／提交在教學上「一步到位」，leader 對 follower 的多輪 nextIndex 回退在一次呼叫內收斂（等效於真實多次 RPC 後達成）。

但以下**皆為真實、正確的 Raft 邏輯，可實際執行**，也正是面試考點：

- **leader election**：term+1、投自己、多數決當選、心跳壓制。
- **投票限制 / 選舉限制**：只投給「log 至少和自己一樣新」的候選人（比 lastLogTerm，平手比 lastLogIndex）→ 保證 leader completeness。
- **log replication**：`prevLogIndex/prevLogTerm` 一致性檢查、不符則 follower 拒絕、leader 回退 `nextIndex` 重試、衝突項目截斷後覆蓋。
- **commit 規則**：某 index 複製到**多數** **且** 該 entry 為**當前 term** 才推進 `commitIndex`（避免提交舊 term entry 的覆蓋風險）。
- **safety**：看到更高 term 立即降為 follower 並更新 term；故障後重新選舉、已 commit 的 log 不丟。

所有寫入用 `flock` 保護 `data/cluster.json`，支援多請求併發。
