# Google Docs 協作編輯器 · PHP Demo

聚焦系統設計考點：**並發編輯的衝突收斂 —— OT（Operational Transformation）的 transform**。
不是 CRUD 存檔，而是「A、B 基於同一版本同時編輯，伺服器 transform 後兩者都套用、結果收斂一致、互不覆蓋」。

## 啟動

```bash
cd demo
php -S localhost:8009 -t public
```

開瀏覽器 <http://localhost:8009>。

## 怎麼看出「收斂」

1. 按 **重設文件** → 內容空白、版本 0。
2. 把 A、B 的 **基準版本 baseVersion 都設成 `0`**（代表兩人都基於同一版本並發編輯）。
3. A：`insert` pos=`0` text=`X` → 送出（文件變 `X`，版本 1）。
4. B：`insert` pos=`0` text=`Y`，**基準版本仍填 `0`**（模擬 B 沒看到 A 的操作）→ 送出。
   - 伺服器發現 B 的 base(0) 落後權威版本(1)，自動把 B 的操作對「中間的 A 操作」做 **transform**：
     A 在 pos 0 已插一字 → B 的 pos 右移 → B 實際插在 pos 1。
   - 結果：`XY`（或視 tie-break 為 `YX`），**兩字都在、誰都沒被覆蓋、版本變 2**。
5. 操作日誌會顯示 `原始:insert(pos=0,...) → transform`，證明位置被調整過。

> 反例直覺：若不做 transform，B 的 pos=0 會把文件覆寫/插錯位，導致 A、B 兩端發散、丟字。transform 正是讓並發操作收斂的關鍵。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（文件內容 + 使用者 A/B 兩個編輯輸入） |
| GET | `/api/doc` | 載入文件狀態 `{ content, version, history }` |
| POST | `/api/op` | 提交操作（OT transform 後套用）`{ baseVersion, kind, pos, text|len, author }` |
| POST | `/api/reset` | 重設文件（清空內容/版本/日誌） |

## API 範例（用 curl 驗證並發收斂）

```bash
# 重設
curl -X POST localhost:8009/api/reset

# A 基於 v0 在 pos 0 插 X
curl -X POST localhost:8009/api/op -H "Content-Type: application/json" \
  -d '{"baseVersion":0,"kind":"insert","pos":0,"text":"X","author":"A"}'

# B 也基於 v0（落後）在 pos 0 插 Y → 伺服器 transform 後套用
curl -X POST localhost:8009/api/op -H "Content-Type: application/json" \
  -d '{"baseVersion":0,"kind":"insert","pos":0,"text":"Y","author":"B"}'

# 看最終收斂內容與版本
curl localhost:8009/api/doc
```

## 檔案

```
src/OTEngine.php   OT 引擎：insert/delete、transform（四種組合）、apply（mb_* 多位元組安全）
src/DocStore.php   文件內容 + 權威版本號 + append-only 操作日誌（檔案鎖保護提交臨界區）
public/index.php   路由 + 深色測試頁（A/B 並發編輯）
data/              執行期產生（已 gitignore）
```

## 示意 vs 真實

| 項目 | 本 demo | 真實系統 |
|---|---|---|
| 傳輸 | 同步 HTTP（提交即回收斂結果） | WebSocket 長連線 + 樂觀本地更新 + ACK |
| 衝突解決 | 最小 OT（insert/insert、insert↔delete、delete/delete 重疊） | 完整 OT（TP1/TP2、富文本屬性）或 CRDT（Yjs/Automerge） |
| 排序 | 單檔案鎖序列化 | 單文件權威節點序列化 + 連線層一致性雜湊分片 |
| 日誌 | JSON 檔 append | append-only 持久化 + 定期快照壓縮 |
| presence | 未實作 | 游標/選取走 Redis pub-sub，與操作管線解耦 |

**收斂核心邏輯（OTEngine::transform / DocStore::submit）為真實可執行**；省略的是傳輸層與完整富文本 transform。
要升級為真正的即時協作：把 HTTP 換成 WebSocket，客戶端做樂觀更新並在收到他人操作時於 client 端也跑一套 transform，即為 Google Docs 的架構雛形。
