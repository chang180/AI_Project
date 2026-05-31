# Typeahead 自動完成 · PHP Demo

聚焦系統設計考點：**Trie 字典樹 → 每個前綴節點預存 Top-K → O(前綴長度) 查詢 → 被選中事件使頻率上升並改變排名**。
不是 CRUD，而是「依頻率排序的前綴 Top-K 建議」。

## 啟動

```bash
cd demo
php -S localhost:8016 -t public
```

開瀏覽器 <http://localhost:8016>：輸入前綴（試試 `自` / `系統` / `sea` / `search`）看 Top-K，
點選某建議使其頻率 +1，再查同前綴看排名變化。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 深色測試頁（輸入框即時顯示建議） |
| GET | `/api/suggest?q=&limit=` | 前綴查詢，回 Top-K（讀，超高頻關鍵路徑） |
| POST | `/api/select` | 被選中事件 `{ term }` → 該詞頻率 +1（寫，影響排名） |
| POST | `/api/reset` | 重設詞頻為種子 |

## API 範例

```bash
# 前綴查詢（中文前綴需 URL 編碼）
curl "localhost:8016/api/suggest?q=search&limit=5"
curl "localhost:8016/api/suggest?q=%E8%87%AA"        # q=自

# 被選中 → 頻率 +1
curl -X POST localhost:8016/api/select -H "Content-Type: application/json" -d '{"term":"searchlight"}'
```

## 操作流程（看排名變化）

1. `GET /api/suggest?q=sea` → 依頻率排序得到 `seattle / season / searchlight ...`。
2. 連續 `POST /api/select {"term":"searchlight"}` 數次，使其頻率超越其他詞。
3. 再 `GET /api/suggest?q=sea` → `searchlight` 排名上升，驗證「頻率更新影響 Top-K 排序」。

## 檔案

```
src/Trie.php        Trie 字典樹：insert(詞,權重)、前綴蒐集完成詞；節點含 ->topK 預存欄位
                    （中文以 PCRE preg_split('//u') 取字元，因本機未載入 mbstring）
src/Suggester.php   建索引時自底向上在每個節點預存 Top-K；suggest() O(前綴長度) 讀出；
                    suggestByScan() 為對照（查詢時才排序）；select() 頻率+1 並重算前綴鏈 Top-K
src/Store.php       JSON 檔保存「詞 => 頻率」（示意離線聚合出的 term_frequency 表）
public/index.php    路由 + 深色測試頁
data/               執行期產生（已被 root .gitignore 涵蓋）
```

## ⚠️ 示意 vs 真實

| 項目 | 本 demo | 真實系統 |
|---|---|---|
| Trie 索引 | 每個請求載入詞頻後重建（PHP 無常駐記憶體） | 駐記憶體、長駐、跨請求共用 |
| Top-K | **節點預存、O(前綴長度) 讀出（真實邏輯）** | 同左，建索引時自底向上算好 |
| 頻率更新 | select() 就地重算前綴鏈並寫回 JSON | 事件丟查詢日誌(Kafka) → 離線/近即時聚合重建 |
| 分片 / 多副本 / CDN 快取 | 無（單機單棵 Trie） | 依前綴分片、熱前綴加副本、邊緣快取 |

核心的 **Trie + 前綴 Top-K 排序 + 頻率影響排名** 為真實可執行，正是擴展時要水平複製/分片的那一塊。
本機 PHP 8.4 未載入 mbstring，故全程不使用 `mb_*`，中文以 PCRE `/u` 模式處理。
