# 🅹 可觀測性

## 共通模式
讓大型系統「**可被觀測**」：用 metrics（指標）、logs（日誌）、traces（追蹤）三大支柱，回答「系統現在健康嗎、出事在哪、為什麼」。重心在**高基數時序資料的攝取、儲存、查詢與告警**。

## 可複用心法
- **三大支柱**：metrics（數值趨勢、便宜、適合告警）、logs（離散事件、可查細節）、traces（跨服務請求路徑）；三者互補。
- **pull vs push**：Prometheus 主動 **pull**（scrape）目標的 `/metrics`，配 service discovery 自動發現；push 適合短命批次任務（pushgateway）。
- **時序資料模型**：series = metric **name + labels** 唯一識別；樣本 = (timestamp, value)；counter（單調遞增）vs gauge（可升可降）。
- **查詢與聚合**：`rate()` 對 counter 取每秒變化率（要處理 counter reset）；`sum by(label)` 跨 series 聚合；避免 **label 高基數**爆炸。
- **告警狀態機**：規則 expr 持續 `for` 一段時間 → `pending` → `firing`；條件解除 → `resolved`；經 Alertmanager 去重/分組/路由。
- **擴展**：本地 TSDB + 壓縮、聯邦 federation、遠端寫入 remote write 做長期儲存。

## 本組題目
| # | 題目 | 該題獨特挑戰 |
|---|---|---|
| 27 | 指標監控（Prometheus） | 時序庫 name+labels、pull scrape、counter/gauge、rate/聚合查詢、告警 firing/resolved |
