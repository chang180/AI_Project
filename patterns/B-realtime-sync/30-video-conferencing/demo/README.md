# 視訊會議 Zoom · PHP Demo

聚焦系統設計考點：**WebRTC 信令中繼（SDP/ICE）→ SFU 轉發表 → 依頻寬選 simulcast 層 → active speaker 偵測**。

> ⚠️ 純模擬「信令 + SFU 轉發決策」邏輯，**不含真實 WebRTC / 媒體傳輸**（PHP 環境無 UDP/SRTP/編解碼）。
> 這些「決策」正是面試核心，且邏輯為真實可執行。

## 啟動

```bash
cd demo
php -S localhost:8030 -t public
```

開瀏覽器 <http://localhost:8030>，按「一鍵建 r1 + 加 Alice/Bob/Carol」，再調某 peer 頻寬看降階、回報音量看主講者。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/join` | 加入 room `{ room, name }` → `{ peer_id, peers }` |
| POST | `/api/sdp` | 中繼 SDP `{ room, from, to, type, sdp }` |
| POST | `/api/ice` | 中繼 ICE candidate `{ room, from, to, candidate }` |
| GET | `/api/signals/{room}/{peer}` | 取走某 peer 信令信箱（測試輔助） |
| GET | `/api/room/{id}` | room 狀態：轉發表 + 各訂閱層 + 主講者 |
| POST | `/api/bandwidth` | 設頻寬 `{ room, peer, kbps }` → `{ layer }` |
| POST | `/api/audio` | 回報音量 `{ room, peer, level }` → `{ active_speaker }` |

## API 範例

```bash
# 建 room 並加入三人
curl -X POST localhost:8030/api/join -d '{"room":"r1","name":"Alice"}'
curl -X POST localhost:8030/api/join -d '{"room":"r1","name":"Bob"}'
curl -X POST localhost:8030/api/join -d '{"room":"r1","name":"Carol"}'

# p1 對 p2 送 offer（信令伺服器中繼），p2 取走信箱
curl -X POST localhost:8030/api/sdp -d '{"room":"r1","from":"p1","to":"p2","type":"offer","sdp":"v=0..."}'
curl localhost:8030/api/signals/r1/p2

# 把 p2 頻寬降到 600kbps → SFU 為它選 mid（自動降階；<500 會再降到 low）
curl -X POST localhost:8030/api/bandwidth -d '{"room":"r1","peer":"p2","kbps":600}'

# p3 大聲說話 → 主講者變 p3
curl -X POST localhost:8030/api/audio -d '{"room":"r1","peer":"p3","level":0.9}'

# 看全貌：轉發表（上行1、下行N−1）、各訂閱層、主講者
curl localhost:8030/api/room/r1
```

## simulcast 選層規則

| 可用頻寬 | 選到的層 | 畫質 |
|---|---|---|
| ≥ 1500 kbps | `high` | 720p |
| ≥ 500 kbps | `mid` | 360p |
| < 500 kbps | `low` | 180p（保底，至少不全黑） |

頻寬下降自動降階、回升自動升階——這就是「視訊品質自適應」的伺服器側實作。

## 檔案

```
src/Sfu.php       room/peer + 信令信箱(SDP/ICE 中繼) + 轉發表 + 依頻寬選層 + 主講者
public/index.php  路由 + 測試頁（建 room / 加 peer / 交換信令 / 調頻寬 / 報音量）
data/             執行期產生（已 gitignore；共享狀態用 flock 保護 read-modify-write）
```

## 從模擬到真實

把 demo 的「決策邏輯」接到真正的 SFU 引擎即可上線：

- **信令**：本 demo 的 SDP/ICE 中繼 → 換成 WebSocket 伺服器（量小、與媒體分離擴展）。
- **媒體 + 選層 + 主講者**：交給 **mediasoup / Janus / Pion** 等 SFU，它們在底層做 SRTP 轉發、simulcast 層切換、audio level 偵測；本 demo 的轉發表、`pickLayer()` 選層、主講者選舉，正是這些引擎對外暴露的「策略」決策。
- **NAT 穿透**：部署 STUN（coturn）讓 peer 取得公網位址；對稱型 NAT 用 TURN 中繼 fallback。
