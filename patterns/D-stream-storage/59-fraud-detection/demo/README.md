# 即時詐欺偵測 · PHP Demo

聚焦系統設計考點：**每筆交易即時評估 → 滑動視窗速度特徵（呼應 ㉒ 廣告聚合）→ 多條規則加權評分 → 風險總分 → 三分決策 allow / review / deny**。不是 CRUD。

## 啟動

```bash
cd demo
php -S localhost:8059 -t public
```

開瀏覽器 <http://localhost:8059>：先「送 5 筆正常交易」看多數放行，再「同卡連送 8 筆」看速度規則被觸發、分數升高、後段交易被判 review/deny；也可即時調門檻看決策怎麼變。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 深色測試頁（送正常交易 / 灌爆同卡觸發速度規則 / 調門檻） |
| POST | `/api/evaluate` | 評估一筆或一批交易，回每筆分數 + 觸發了哪些規則 + 決策 |
| GET | `/api/state` | 目前設定（門檻/權重）、黑名單、滑動視窗鍵數、決策統計 |
| POST | `/api/config` | 即時調整規則門檻 / 權重 / 決策分界（可一併加黑名單） |
| POST | `/api/reset` | 清空滑動視窗 / 黑名單 / 統計，回預設設定 |

## API 範例

```bash
# 評估一批交易（未帶 ts 者用伺服器當下時間）
curl -X POST localhost:8059/api/evaluate -H 'Content-Type: application/json' -d '{"transactions":[
  {"txn_id":"n1","card_id":"card_1","ip":"10.0.0.1","device":"d1","amount":120},
  {"txn_id":"n2","card_id":"card_2","ip":"10.0.0.2","device":"d2","amount":150}
]}'

# 同一張卡短時間連送多筆 → 觸發速度規則（velocity_card），分數升高
curl -X POST localhost:8059/api/evaluate -H 'Content-Type: application/json' -d '{"transactions":[
  {"txn_id":"f1","card_id":"card_FLOOD","ip":"66.66.66.66","amount":300,"ts":1717200000},
  {"txn_id":"f2","card_id":"card_FLOOD","ip":"66.66.66.66","amount":300,"ts":1717200000},
  {"txn_id":"f3","card_id":"card_FLOOD","ip":"66.66.66.66","amount":300,"ts":1717200000},
  {"txn_id":"f4","card_id":"card_FLOOD","ip":"66.66.66.66","amount":300,"ts":1717200000}
]}'

# 調門檻 / 加黑名單（即時生效）
curl -X POST localhost:8059/api/config -H 'Content-Type: application/json' \
  -d '{"velocity_card_max":2,"deny_at":80,"blacklist":["card_EVIL"]}'

# 看目前狀態
curl localhost:8059/api/state
```

## 規則與評分

| 規則 | 命中條件 | 預設權重 |
|---|---|---|
| `velocity_card` | 同卡在滑動視窗（預設 60 秒）內筆數 > `velocity_card_max`(3) | +40 |
| `velocity_ip` | 同 IP 在滑動視窗內筆數 > `velocity_ip_max`(5) | +25 |
| `amount` | 單筆金額 > `amount_max`(1000) | +30 |
| `blacklist` | 卡號 / IP / 裝置任一命中黑名單 | +80 |
| `model` | ML 模型風險分（**加權示意**：金額/速度越高分越高） | 平滑加權 |

命中規則的權重累加成**風險總分**，再用兩個分界映射成三分決策：

```
score < review_at(50)            → allow （放行）
review_at <= score < deny_at(90) → review（送人工審查）
score >= deny_at(90)             → deny  （拒絕）
```

## 檔案

```
src/SlidingWindow.php  每個鍵(卡/IP)維護最近交易時間戳，回傳視窗內筆數 = 速度特徵（呼應 ㉒）
src/RuleEngine.php     多條規則加權評分 → 風險總分 → 三分決策 allow/review/deny；門檻可即時調
public/index.php       路由（evaluate / state / config / reset）+ 深色測試頁
data/                  執行期產生（已 gitignore）
```

## 預期觀察（同卡連送）

同一張卡 `card_FLOOD` 連送（門檻 `velocity_card_max=3`）：

- 前 3 筆：速度未超門檻，只有 `model` 小分 → `allow`
- 第 4 筆起：同卡視窗筆數 > 3 → 觸發 `velocity_card`（+40），分數跳到 50 以上 → `review`
- 連送更多、又同 IP 超門檻時：再疊 `velocity_ip`（+25）→ 分數逼近/超過 `deny_at` → `deny`

> 重點不在數字背誦，而在看到 **「滑動視窗速度特徵」把短時間連續交易的風險即時反映成分數，多條規則加權後由分數三分決策**。把 `velocity_card_max` 調小、或把 `review_at`/`deny_at` 調低，就能看到同一批交易的決策整體往 review/deny 移動——這正是詐欺系統「調鬆調緊」在權衡**誤殺 vs 漏抓**。

## ⚠️ 示意 vs 真實

| 元件 | 本 demo | 真實系統 |
|---|---|---|
| 事件來源 | HTTP 批次餵入、單機單行程 | 交易閘道即時呼叫 + Kafka 串流 |
| 線上特徵 | 記憶體 / JSON 持久化的滑動視窗 | Redis / Flink keyed-state，低延遲特徵庫 + TTL |
| 規則引擎 | PHP 加權評分（真實可執行） | 規則 DSL / 決策表 + 熱更新 |
| ML 模型 | `model` 規則為**加權示意**，非真模型 | 線上推論服務（GBDT / DNN），離線回饋再訓練 |
| 決策落地 | 回傳 allow/review/deny | 同步擋交易 + 寫審查佇列 + 回饋標記 |

**滑動視窗速度特徵、多條規則加權評分、風險總分三分決策、即時調門檻皆為真實可執行**；模型分數為可解釋的加權示意，分散式基建以單機示意。
