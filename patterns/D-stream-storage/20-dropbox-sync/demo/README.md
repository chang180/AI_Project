# Dropbox / Google Drive 檔案同步 · PHP Demo

聚焦系統設計考點：**檔案分塊 → 內容定址去重 → 增量同步（delta）→ 版本向量衝突偵測**。
這不是 CRUD：重點在「相同內容只存一份」「只上傳變更的那一小塊」「並發改同檔不靜默覆蓋」。

## 啟動

```bash
cd demo
php -S localhost:8020 -t public
```

開瀏覽器 <http://localhost:8020>，把文字內容當成一個「檔案」上傳。

## 操作流程（看出三大核心）

1. **首次上傳**：`fileId=report.txt`、`baseVersion=0`、貼一段內容 → 看切成幾個 chunk、各 hash、產生 v1。
2. **小改再上傳**：把內容尾端小改一點、`baseVersion=1` 再上傳 → 只有**變更的 chunk** 被新增（missing/delta），前面沒變的 chunk 因去重沿用，顯示 delta bytes 與省下的頻寬比率。
3. **模擬衝突**：切到 `裝置B`，`baseVersion` 仍填**舊版本**（代表它沒看到裝置A 的最新提交）再上傳改動 → 伺服器偵測到 baseVersion 落後，**不覆蓋**，改產生衝突副本 `report.txt (裝置B 的衝突副本)`。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（上傳、統計、檔案列表） |
| POST | `/api/upload` | 上傳新版本 `{ fileId, content, device, baseVersion }`，回傳分塊 / delta / 衝突結果 |
| GET | `/api/file/{id}/meta` | 某檔最新版本 metadata（有序 chunk hash 清單 + 版本向量） |
| GET | `/api/blob/stats` | 全域去重統計（唯一 chunk 數、實際儲存、去重命中、省下位元組） |

## API 範例

```bash
# 首次上傳 v1
curl -X POST localhost:8020/api/upload \
  -H 'Content-Type: application/json' \
  -d '{"fileId":"a.txt","content":"The quick brown fox jumps over the lazy dog.","device":"裝置A","baseVersion":0}'

# 小改後增量上傳 v2（觀察 missing_chunks / delta_bytes / saved_ratio）
curl -X POST localhost:8020/api/upload \
  -H 'Content-Type: application/json' \
  -d '{"fileId":"a.txt","content":"The quick brown fox jumps over the lazy dog!!","device":"裝置A","baseVersion":1}'

# 裝置B 基於舊版本 v1 改動 → 偵測衝突
curl -X POST localhost:8020/api/upload \
  -H 'Content-Type: application/json' \
  -d '{"fileId":"a.txt","content":"Totally different content from device B.","device":"裝置B","baseVersion":1}'

curl localhost:8020/api/blob/stats
```

## 檔案

```
src/Chunker.php       把內容切成固定大小 chunk、逐塊算 sha256（以位元組切）
src/BlobStore.php     內容定址儲存：以 hash 為 key、相同 chunk 只存一份、引用計數 + 去重統計
src/FileIndex.php     檔案 → 有序 chunk hash 清單 + 版本號 + 版本向量（模擬 metadata DB）
src/SyncService.php   chunks:check 算 delta、只上傳新出現的 chunk、版本向量偵測衝突
public/index.php      路由 + 深色測試頁
data/                 執行期自建（已被 root .gitignore 涵蓋）
```

## ⚠️ 示意 vs 真實

| 面向 | 本 Demo | 真實系統 |
|---|---|---|
| 分塊方式 | 固定大小（16 byte，方便觀察） | **CDC 內容定義分塊**（Rabin 滾動雜湊，抗邊界位移） |
| chunk hash | sha256 截短 12 字元（顯示好看） | 完整 sha256（64 hex） |
| metadata | JSON 檔 | 分片式關聯庫（files / file_versions） |
| blob | 本機 JSON | 物件儲存 S3 / GCS，多副本 / EC 編碼 |
| 同步通知 | 無（測試頁手動上傳） | 長連線 / 推播閘道，commit 後扇出「該拉取」 |

**真實可執行的系統邏輯**：固定分塊、sha256 內容定址、相同 chunk 去重、引用計數、
delta 差集（只傳 missing）、版本向量衝突偵測與衝突副本生成。

本機 PHP **未載入 mbstring**，故全程以**位元組（byte）**切塊與計長度——
這其實與真實系統一致：分塊面對的是二進位內容，本就不該以字元為單位。
