# 物件儲存 S3 · PHP Demo

聚焦系統設計考點：**multipart 上傳（init→part→complete + ETag）→ 一致性雜湊放置 → 副本 vs 抹除碼 → 節點失效還原 → 預簽 URL（hash_hmac）**。

## 啟動

```bash
cd demo
php -S localhost:8033 -t public
```

開瀏覽器 <http://localhost:8033>。按「一鍵 multipart 上傳」（會 init→上傳 2 個 part→complete 組裝），再點某節點「製造失效」，回到物件列表按「下載/還原」看它用 parity（或其他副本）還原並比對 crc32；按「預簽 URL」看 hash_hmac 簽章驗證。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/upload?op=init` | `{ bucket, key }` → 回 `uploadId` |
| POST | `/api/upload?op=part` | `{ uploadId, partNumber, content }` → 回該 part ETag（md5） |
| POST | `/api/upload?op=complete` | `{ uploadId, strategy(EC\|REPLICA), replicas? }` → 組裝、算 multipart ETag、放置、寫 manifest |
| GET | `/api/object` | `?bucket=&key=`（可加 `&expires=&sig=` 走預簽驗章）→ 讀回物件，遇失效節點自動還原 |
| POST | `/api/node-fail` | `{ node, failed }` → 標記節點失效/恢復 |
| GET | `/api/presign` | `?bucket=&key=&ttl=` → 產生預簽 URL |
| GET | `/api/state` | 各節點 block 數、失效節點、bucket、物件數 |

## API 範例

```bash
# 1) init
curl -s -X POST "localhost:8033/api/upload?op=init" \
  -H "Content-Type: application/json" -d '{"bucket":"my-bucket","key":"hello.txt"}'
# → 取得 uploadId（下面用 $UP 代）

# 2) 上傳兩個 part（part ETag = md5(內容)）
curl -s -X POST "localhost:8033/api/upload?op=part" \
  -H "Content-Type: application/json" -d '{"uploadId":"'$UP'","partNumber":1,"content":"Hello "}'
curl -s -X POST "localhost:8033/api/upload?op=part" \
  -H "Content-Type: application/json" -d '{"uploadId":"'$UP'","partNumber":2,"content":"World"}'

# 3) complete（EC = 抹除碼 k2+m1，1.5x 空間；REPLICA = 副本 R 份）
curl -s -X POST "localhost:8033/api/upload?op=complete" \
  -H "Content-Type: application/json" -d '{"uploadId":"'$UP'","strategy":"EC"}'
# → manifest：multipart ETag（md5(各 part md5 串接)-N）、placement（哪些分片落哪些節點）

# 4) 製造節點失效後下載 → 用 parity 還原、crc32 比對一致
curl -s -X POST "localhost:8033/api/node-fail" \
  -H "Content-Type: application/json" -d '{"node":"node-a","failed":true}'
curl -s "localhost:8033/api/object?bucket=my-bucket&key=hello.txt"
# → recovered:true, integrityOk:true，內容不變

# 5) 預簽 URL（過期或竄改 sig → 403）
curl -s "localhost:8033/api/presign?bucket=my-bucket&key=hello.txt&ttl=60"
```

## 檔案

```
src/Erasure.php     抹除碼（教學版）：k=2 資料 + m=1 XOR parity；encode / decode（缺一片用 XOR 還原）
src/ObjectStore.php 核心：multipart(init/part/complete + ETag) / 一致性雜湊 placeNodes /
                    副本&抹除碼放置 / 節點失效 / fetchObject(還原) / metadata(flock 強一致) / 預簽 URL
public/index.php    路由 + 測試頁
data/               執行期產生（已 gitignore）：
                      nodes/<node>/*.blk  各「儲存節點」上的 block（副本或分片）
                      manifests.json      bucket/key → manifest（強一致，flock 序列化讀改寫）
                      uploads.json        進行中的 multipart 暫存
                      buckets.json        bucket 清單
                      failed_nodes.json   被標記失效的節點
```

## 空間取捨（核心考點）

| 策略 | 存什麼 | 空間倍率 | 可容忍遺失 |
|---|---|---|---|
| 副本 REPLICA（R=3） | 整顆物件 ×3 | 3.0x | 2 份 |
| 抹除碼 EC（k=2,m=1） | 2 資料 + 1 parity | 1.5x | 1 份 |

一般化：抹除碼空間倍率 = `(k+m)/k`；副本 = `R`。抹除碼用更少空間換到相近可靠度，代價是還原要算（CPU）。真實 S3 對冷資料多用 EC（如 RS(10,4)，1.4x 容忍 4 份遺失），熱資料/小檔可能用副本降延遲。

## ⚠️ 誠實聲明：這是單機教學版

用 `data/nodes/` 下的子目錄模擬「儲存節點」，**沒有**真正的網路、分散式協調、背景修復(repair)、跨區複製或 RS 有限體運算（XOR parity 只能容忍 m=1）。

但 **multipart 組裝與 ETag、一致性雜湊放置、抹除碼編碼/還原、副本容錯讀取、metadata 強一致（flock 序列化）、預簽 URL 的 hash_hmac 簽章與過期驗證** 皆為**真實可執行**——這些正是面試考點。所有共享狀態寫入用 `flock` 保護，支援多請求併發。
