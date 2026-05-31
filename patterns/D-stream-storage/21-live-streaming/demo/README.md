# 直播平台 · PHP Demo

聚焦系統設計考點：**即時攝入 → 滑動視窗 HLS manifest → 聊天 fan-out → 同時在線計數**。
這不是 CRUD demo —— 核心是「直播 manifest 只留 live edge 附近數段、舊段滾掉、MEDIA-SEQUENCE 遞增」與「一則聊天扇出給房內所有觀眾」。

## 啟動

```bash
cd demo
php -S localhost:8021 -t public
```

開瀏覽器 <http://localhost:8021>。

## 操作流程

1. **開始直播**（輸入標題）→ 視窗清空、media sequence 歸零、聊天室重置。
2. 多次按 **＋ 產生 segment** → 模擬主播推流被即時轉碼切片，推進 live edge。
   觀察：超過 N 段（預設 N=3）時最舊一段滾掉、`EXT-X-MEDIA-SEQUENCE` 遞增。
3. 取 **直播 manifest** → 只列最近 N 個 segment、無 `#EXT-X-ENDLIST`（直播中）。
4. 觀眾 **加入** 看同時在線人數 → **送聊天訊息** 看 fan-out 觸及人數。
5. **結束直播** → manifest 補上 `#EXT-X-ENDLIST`。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET  | `/` | 測試頁 |
| POST | `/api/streams/start` | 開始直播 `{ title }` |
| POST | `/api/streams/segment` | 攝入推進：產生一個新 segment |
| POST | `/api/streams/stop` | 結束直播（manifest 補 ENDLIST） |
| GET  | `/api/streams/{id}/master.m3u8` | 主清單（各碼率 ABR） |
| GET  | `/api/streams/{id}/720p/index.m3u8` | 直播 media manifest（滑動視窗） |
| POST | `/api/streams/join` | 觀眾加入 `{ viewer }` |
| POST | `/api/streams/leave` | 觀眾離開 `{ viewer }` |
| POST | `/api/streams/chat` | 送聊天 `{ user, text }` → fan-out |
| GET  | `/api/streams/status` | 目前狀態（視窗 / 人數 / 聊天）JSON |

## API 範例

```bash
curl -X POST localhost:8021/api/streams/start -d "title=test"
curl -X POST localhost:8021/api/streams/segment      # 重複數次推進 live edge
curl localhost:8021/api/streams/live_demo/720p/index.m3u8   # 看滑動視窗 manifest
curl -X POST localhost:8021/api/streams/join -d "viewer=alice"
curl -X POST localhost:8021/api/streams/join -d "viewer=bob"
curl -X POST localhost:8021/api/streams/chat -d "user=alice&text=GG"  # fanout=2
```

## 檔案

```
src/IngestService.php   攝入：開始/結束直播 + 產生遞增 sequence 的 segment（檔案鎖）
src/HlsPackager.php     ★ 滑動視窗 manifest：只留最近 N 段、MEDIA-SEQUENCE 遞增、無 ENDLIST
src/ChatRoom.php        聊天 fan-out（房內每位觀眾各有收件匣）+ ViewerCounter 同時在線
public/index.php        路由 + 深色測試頁
data/                   執行期產生（已被 root .gitignore 涵蓋）
```

## ⚠️ 示意 vs 真實

**示意（非真實）**：
- 無真正的 RTMP / SRT / WebRTC 攝入，「產生 segment」是手動推進模擬主播推流。
- 無 `ffmpeg` 即時轉碼、無真正的 H.264 切片，segment 為佔位文字（`seg_N.ts`）。
- 無真正的 CDN 邊緣分發，無 WebSocket 推播（聊天用收件匣 + 輪詢模擬扇出）。

**真實可執行的系統邏輯**：
- **滑動視窗 manifest**：只保留最近 N 個 segment、舊段滾掉、`EXT-X-MEDIA-SEQUENCE` 隨之遞增、直播中無 `#EXT-X-ENDLIST` —— 與真實 HLS 直播 media playlist 行為一致。
- **聊天 fan-out**：一則訊息複製進房內每位觀眾的收件匣，回傳扇出觸及人數，呈現「訊息數 × 觀眾數」的放大成本。
- **同時在線計數**：以 join/leave 維護 presence。

真實系統用 `RTMP/SRT/WebRTC` 攝入、`ffmpeg`（或雲端 MediaLive 類服務）即時轉碼切片、`CDN` 全球分發 segment、`WebSocket` + 階層式廣播做聊天 fan-out、`Redis`/HLL 做近似在線計數。
