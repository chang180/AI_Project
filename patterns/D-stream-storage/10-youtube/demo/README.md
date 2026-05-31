# YouTube 影片儲存/串流 · PHP Demo

聚焦系統設計考點：**上傳（分塊 → 斷點續傳 → 合併）→ 轉碼管線狀態機（QUEUED→TRANSCODING→READY）→ 自適應串流 manifest（HLS）→ 觀看計數**。
不是 CRUD：重點在「非同步管線」與「漸進可用」。

## 啟動

```bash
cd demo
php -S localhost:8010 -t public
```

開瀏覽器 <http://localhost:8010>：
1. 「上傳影片」→ 一鍵建立上傳、分 3 塊、合併、觸發轉碼。
2. 在影片列按「推進轉碼一步」，觀察 240p→480p→720p→1080p 依序由 `QUEUED→TRANSCODING→READY`。
3. 點 `master.m3u8` 看自適應 manifest（只列已就緒的解析度，呈現漸進可用）。
4. 「觀看 +1」累加觀看數。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/v1/uploads` | 建立上傳 `{ filename, size, title }` → `upload_id` + 分塊預簽章 URL |
| PUT | `/api/v1/uploads/{uid}/parts/{i}` | 上傳第 i 塊（body=bytes），可重傳（斷點續傳） |
| POST | `/api/v1/uploads/{uid}/complete` | 合併分塊 → 建影片 → 觸發轉碼（202 Accepted） |
| POST | `/api/v1/videos/{vid}/transcode/step` | 推進轉碼一步（模擬 Worker） |
| POST | `/api/v1/videos/{vid}/transcode/run` | 一路推到全 READY |
| GET | `/api/v1/videos/{vid}` | 影片狀態 + 各解析度 job 狀態 |
| GET | `/api/v1/videos/{vid}/master.m3u8` | master manifest（自適應，只列已 READY 解析度） |
| GET | `/api/v1/videos/{vid}/{rendition}/index.m3u8` | 某解析度 media manifest（列出 segment .ts） |
| POST | `/api/v1/videos/{vid}/view` | 觀看數 +1 |

## API 範例

```bash
# 1) 建立上傳（size 決定切幾塊；CHUNK=5MB）
curl -s -X POST localhost:8010/api/v1/uploads \
  -H 'Content-Type: application/json' \
  -d '{"filename":"a.mp4","size":12,"title":"範例"}'
# → { "upload_id":"up_xxx", "video_id":"vid_xxx", "total_parts":1, "presigned_urls":[...] }

# 2) 上傳分塊
curl -s -X PUT localhost:8010/api/v1/uploads/up_xxx/parts/0 --data-binary 'HELLO-VIDEO'

# 3) 完成 → 觸發轉碼（202）
curl -s -X POST localhost:8010/api/v1/uploads/up_xxx/complete

# 4) 推進轉碼（多按幾次，觀察各解析度狀態前進）
curl -s -X POST localhost:8010/api/v1/videos/vid_xxx/transcode/run

# 5) 取自適應 manifest
curl -s localhost:8010/api/v1/videos/vid_xxx/master.m3u8

# 6) 觀看 +1
curl -s -X POST localhost:8010/api/v1/videos/vid_xxx/view
```

## 檔案

```
src/UploadService.php      建立上傳(發 upload_id + 預簽章 URL 概念) / 接收分塊 / 合併完成 → 觸發轉碼
src/TranscodePipeline.php  fan-out 多解析度 job + 狀態機(QUEUED→TRANSCODING→READY) + 產 HLS .m3u8
src/MetadataStore.php      影片中繼資料 + 各解析度狀態 + 觀看數(JSON 檔模擬 DB)；並模擬物件儲存(原片/分段)
public/index.php           路由 + 深色測試頁
data/                      執行期產生（已 gitignore）：videos.json / uploads.json / objects/
```

## ⚠️ 示意 vs 真實

本 demo **不做真正的影片編碼**，重點在「系統管線邏輯」：

| 面向 | 本 demo | 真實系統 |
|---|---|---|
| 影片本體 | `data/objects/` 下的檔案（佔位 bytes） | 物件儲存 S3 / GCS（PB 級） |
| 分塊上傳 | 自家端點接 bytes，合併寫檔 | 預簽章 URL 直傳物件儲存，multipart complete |
| 轉碼 | 狀態機推進，產出 `.m3u8` 文字 | ffmpeg 真實轉多解析度/多碼率 + 切 `.ts` |
| 分發 | 直接由 App 回 manifest | CDN 邊緣快取 segment，源站只服務回源 miss |
| 觀看計數 | 直接累加（檔案鎖） | Kafka 非同步聚合 + 去重防刷 |

**真實可執行的系統邏輯**：分塊/斷點續傳、合併、轉碼 job 狀態機（含低解析度優先的漸進可用）、
HLS master/media manifest 結構、觀看計數的關鍵路徑解耦。這些正是面試的考點。
