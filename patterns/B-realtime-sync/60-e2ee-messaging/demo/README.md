# E2EE Messaging · PHP Demo

聚焦系統設計考點：**金鑰交換（Diffie-Hellman）→ KDF 導對稱金鑰 → 棘輪推進（前向保密）→ 伺服器只中繼密文**，而不是訊息 CRUD。組合了 ⑦ Messenger（中繼存轉）+ ㉔ 認證安全（金鑰協商）。

## 啟動

```bash
cd demo
php -S localhost:8060 -t public
```

開瀏覽器 <http://localhost:8060>，操作流程：

1. 按「**① A、B 建立加密 session**」→ 兩個裝置各自用 DH 算出**相同的共享祕密**（畫面顯示 ✅ 兩邊一致），再經 KDF 導出根金鑰、開啟棘輪。
2. 在 A 的裝置輸入明文「**🔒 加密送給 B**」→ 訊息在 A 端加密，**伺服器只收到密文**（看「伺服器視角」面板）。
3. 在 B 的裝置按「**📥 收 B 的訊息並解密**」→ B 端用接收棘輪金鑰解出明文。
4. 每送一則，棘輪 `send_n` / `recv_n` 就推進一次（畫面顯示鏈金鑰指紋變動）。
5. 按「**② 示範前向保密**」→ 嘗試用「已被棘輪覆蓋的舊金鑰」重解舊訊息 → 失敗（金鑰已單向銷毀）。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁（A / B 兩裝置 + 伺服器密文視角） |
| POST | `/api/session` | 建立 session `{ user, peer }` → 自動 register + DH 算共享祕密 + 開棘輪 |
| POST | `/api/send` | 加密發送 `{ from, to, body }` → 伺服器只拿到密文 |
| GET | `/api/inbox?user=&peer=` | 收取並解密（裝置端用接收棘輪金鑰） |
| GET | `/api/server` | 伺服器視角：公鑰目錄 + 密文信箱（證明只見密文） |
| POST | `/api/forward-secrecy` | 示範前向保密：舊金鑰解不了（已被棘輪覆蓋） |
| GET | `/api/state` | 公鑰目錄 + 信箱 + 棘輪狀態，給測試頁刷新 |

## API 範例

```bash
curl -X POST localhost:8060/api/session -H "Content-Type: application/json" -d '{"user":"A","peer":"B"}'
curl -X POST localhost:8060/api/send    -H "Content-Type: application/json" -d '{"from":"A","to":"B","body":"哈囉"}'
curl "localhost:8060/api/inbox?user=B&peer=A"
curl "localhost:8060/api/server"
curl -X POST localhost:8060/api/forward-secrecy -H "Content-Type: application/json" -d '{"user":"B","peer":"A"}'
```

## 檔案

```
src/ToyCrypto.php     玩具版密碼學：DH 模冪、KDF(hash)、棘輪推進、XOR 對稱加密
src/KeyServer.php     金鑰伺服器：發布/領取公鑰（只存公鑰，無私鑰）+ 指紋
src/DeviceStore.php   裝置端：身分私鑰 + 每對話的棘輪狀態（send/recv 鏈，永不上伺服器）
src/Mailbox.php       伺服器端密文信箱：只存 { from, to, n, cipher }，無明文
src/E2EEService.php   流程編排：register → openSession(DH) → send(加密) → receive(解密)
public/index.php      路由 + 深色測試頁
data/                 執行期產生（已 gitignore）
```

## ⚠️ 嚴正誠實聲明（務必閱讀）

本 demo 用 **小數字 Diffie-Hellman（p=467）+ XOR 對稱加密**，純為「看得懂」的**教學示意**，
**絕對不是安全加密**：

| 面向 | 真實 Signal | 本 demo（示意） |
|---|---|---|
| 金鑰交換 | X3DH（Curve25519，256-bit+） | 小質數 DH（p=467，模冪可秒破） |
| 對稱加密 | AES-GCM（帶完整性驗證） | XOR keystream（**無認證、可竄改**） |
| 棘輪 | Double Ratchet（DH 棘輪 + 對稱鏈棘輪） | 只示意對稱鏈棘輪（hash 單向推進） |
| 大數運算 | 專用大數庫 / 橢圓曲線 | PHP 整數（刻意用小數字避免溢位） |

**但以下概念為真、與 Signal Protocol 一致**：DH 雙方各算出相同共享祕密（竊聽者僅見公鑰算不出）、
用 KDF 把共享祕密導成對稱金鑰、棘輪每則訊息單向推進（舊金鑰算不出新的）達成**前向保密**、
伺服器只是「不識內容的郵差」只中繼密文。把玩具密碼學換成 X3DH + Double Ratchet + AES/Curve25519，架構與流程不變。
