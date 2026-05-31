# Messenger · PHP Demo

聚焦系統設計考點：**連線註冊表（user→gateway）→ 路由投遞 → per-conversation 順序 / 已讀回條 / 離線佇列補收**，而不是訊息 CRUD。

## 啟動

```bash
cd demo
php -S localhost:8007 -t public
```

開瀏覽器 <http://localhost:8007>，操作流程：

1. A、B 各自按「上線」→ presence 變在線、登記到各自 gateway（gw-1 / gw-2）。
2. A 輸入訊息「送給 B」→ B 在線 → **即時投遞**（序號遞增）。
3. B 按「poll 收件匣」→ 收到訊息（依序號排序）。
4. B 按「標記已讀」→ A 的對話歷史出現 **✓✓ 已讀** 回條。
5. B 按「下線」→ A 再送 → 進**離線佇列**並觸發推播。
6. B 重新「上線」→ 自動**補收**離線訊息 → 再 poll 即可看到。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（A / B 兩個對話面板） |
| POST | `/api/connect` | 上線 `{ user, gateway }` → 註冊 + 補收離線 |
| POST | `/api/disconnect` | 下線 `{ user }` |
| POST | `/api/heartbeat` | 心跳 `{ user }`（維持 presence TTL） |
| POST | `/api/send` | 送訊息 `{ from, to, body }` → 在線投遞 / 離線佇列 |
| GET | `/api/poll?user=` | poll 收件匣（模擬 WS 被動收訊） |
| POST | `/api/read` | 已讀回條 `{ reader, peer }` |
| GET | `/api/history?a=&b=` | 對話歷史（含序號與已讀狀態） |
| GET | `/api/state` | presence + 推播紀錄 |

## API 範例

```bash
curl -X POST localhost:8007/api/connect  -d '{"user":"B","gateway":"gw-2"}'
curl -X POST localhost:8007/api/send     -d '{"from":"A","to":"B","body":"哈囉"}'
curl "localhost:8007/api/poll?user=B"
curl -X POST localhost:8007/api/read     -d '{"reader":"B","peer":"A"}'
curl "localhost:8007/api/history?a=A&b=B"
```

## 檔案

```
src/ConnectionRegistry.php   連線註冊表：user→gateway 對映 + presence（online/TTL/心跳）
src/MessageStore.php         訊息持久化 + 收件匣 + 離線佇列 + per-conversation 序號 + 已讀
src/MessageRouter.php        核心：持久化 → 查註冊表 → 在線投遞 / 離線進佇列 + 補收
public/index.php             路由 + 深色測試頁
data/                        執行期產生（已 gitignore）
```

## ⚠️ 示意 vs 真實

| 面向 | 真實 Messenger | 本 demo |
|---|---|---|
| 連線 | 億級 **WebSocket** 長連線 | 無連線；用 HTTP 請求模擬 |
| 投遞 | 收件人那台 gateway 主動 **push** | 寫入收件匣，client 主動 **poll** 取代 push |
| 跨機轉發 | **Redis Pub/Sub** 把訊息送到對方 gateway | 直接寫共用收件匣（同一進程） |
| 註冊表 | **Redis** `user → gateway` | JSON 檔模擬 |
| 推播 | **APNs / FCM** | 記一筆 push_log 示意 |

**系統邏輯為真實可執行**：連線註冊表查詢、在線/離線路由決策、per-conversation 單調序號、已讀回條、離線佇列補收與排序、presence + TTL，皆與真實設計一致；換成 WebSocket + Redis 後架構不變。
