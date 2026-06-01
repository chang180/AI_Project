# MapReduce / Word Count · PHP Demo

聚焦系統設計考點：**輸入切片（split）→ map（每詞 (詞,1)）→ shuffle（依詞分組、partition 給 reducer）→ reduce（加總）**，並把每一階段的中間產物攤開來看。

## 啟動

```bash
cd demo
php -S localhost:8053 -t public
```

開瀏覽器 <http://localhost:8053>，貼一段文字，按「跑 MapReduce Job」，即可看到三階段：
每個 map task 吐出的 `(詞,1)`、shuffle 後每個 reducer 收到哪些詞（同詞匯到同 reducer）、每個 reducer 加總的結果，以及合併後的全域 word count。

調整「reduce 任務數 (R)」會看到 `partition = hash(詞) % R` 把詞重新分組；勾選 **combiner** 會看到中間對數量變少（map 端先本地加總）。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（貼文字、跑 job、看三階段） |
| POST | `/api/job` | 跑 job `{ text, mappers, reducers, combiner }` → 回完整三階段 JSON |
| GET | `/api/state` | 讀回上次 job 的完整中間產物 |

## API 範例

```bash
curl -X POST localhost:8053/api/job \
  -H 'Content-Type: application/json' \
  -d '{"text":"the fox the dog the fox","mappers":3,"reducers":2}'

curl localhost:8053/api/state
```

## 檔案

```
src/Splitter.php   輸入切片：把文字依行切成 N 個 split（餵給 N 個 map task）
src/Mapper.php     map：每行斷詞，每個詞輸出 (詞,1)；可選 combiner 本地預聚合
src/Shuffler.php   shuffle/sort：依 partition = hash(詞)%R 分組，同詞匯到同 reducer
src/Reducer.php    reduce：每個詞把 value 清單加總（換聚合函式即換 job）
src/Job.php        master：模擬多 map/reduce task 平行，串三階段並保留中間產物（flock 落地）
public/index.php   路由 + 三階段視覺化測試頁
data/              執行期產生（last_job.json）
```

## ⚠️ 誠實聲明

本 demo 在**單機**上用迴圈**模擬**多個 map / reduce worker 平行執行；真實 MapReduce 由 master 排程、把 task 分散到叢集上的 worker，並處理容錯重跑、落後者 speculative execution、跨網路 shuffle。
但 **split → map → shuffle（含 `hash(詞)%R` partition、同詞匯同 reducer）→ reduce（加總）的三階段邏輯與中間產物為真實可執行**。要擴成分散式，把 map/reduce task 換成跨機 worker、shuffle 換成跨網路拉取即可，三階段的計算邏輯一字不用改。
