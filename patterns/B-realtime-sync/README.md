# 🅱️ 即時連線 / 同步

## 共通模式
客戶端與伺服器維持**長連線**，需要低延遲地推送/同步狀態，並在多人並發下保持一致。

## 可複用心法
- **連線層**：WebSocket / SSE；用 **connection registry**（哪個 user 在哪台 server）+ Redis Pub/Sub 做跨機轉發。
- **presence（在線狀態）**：心跳 + TTL；斷線偵測。
- **訊息順序與保證**：per-conversation 序號、ACK、離線訊息暫存。
- **衝突解決**：協作編輯用 **OT（Operational Transform）** 或 **CRDT**；理解兩者取捨。
- **擴展**：水平擴展連線伺服器，用一致性雜湊/sticky 分配；狀態外置到 Redis。

## 本組題目
| # | 題目 | 該題獨特挑戰 |
|---|---|---|
| 05 | RoboTaxi | 地理空間配對、即時位置串流、路線優化 |
| 07 | Messenger | 億級在線、fan-out、已讀回條、離線推送 |
| 09 | Google Docs | 字元級即時同步、OT/CRDT 衝突解決、游標共享 |
| 30 | 視訊會議（Zoom） | 信令 SDP/ICE、SFU vs MCU、simulcast 依頻寬降階、active speaker |
| 57 | 外送平台（DoorDash） | 三邊市場、geohash 找最近外送員、CAS 原子指派、訂單狀態機 + 即時 ETA |
| 60 | E2EE 加密通訊（Signal） | DH 金鑰交換、KDF、雙棘輪前向保密、伺服器只見密文（教學示意密碼學） |
