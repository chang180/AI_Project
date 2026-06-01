# OAuth 2.0 / SSO · PHP Demo

聚焦系統設計考點：**Authorization Code 流程 → 一次性授權碼 → JWT access_token → refresh 輪替 → 撤銷名單 → 受保護資源驗章**。

## 啟動

```bash
cd demo
php -S localhost:8024 -t public
```

開瀏覽器 <http://localhost:8024>。首頁會在伺服器端就地跑完整流程，逐步列出結果，並示範三種攻防：授權碼重放被擋、撤銷後 refresh 失效、竄改 token 驗章失敗。

## 端點

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 一鍵示範頁（authorize→token→/api/me + 三種攻防） |
| GET | `/authorize` | 驗 client/redirect/scope → 模擬同意 → 發一次性授權碼 |
| POST | `/token` | `grant_type=authorization_code` 或 `refresh_token` |
| POST | `/revoke` | 撤銷 refresh_token（加入撤銷名單） |
| GET | `/api/me` | 受保護資源：帶 `Authorization: Bearer <jwt>` → 回使用者 |

## API 範例

```bash
# 1) 拿授權碼
curl "localhost:8024/authorize?client_id=demo-web&redirect_uri=http://localhost:8024/callback&scope=openid%20profile%20email"

# 2) code 換 token（一次性，重放會報 invalid_grant）
curl -X POST localhost:8024/token \
  -d "grant_type=authorization_code&client_id=demo-web&client_secret=s3cr3t-demo-web&code=<code>&redirect_uri=http://localhost:8024/callback"

# 3) 帶 Bearer 打受保護資源
curl localhost:8024/api/me -H "Authorization: Bearer <access_token>"

# 4) refresh 換新 access（舊 refresh 輪替作廢）
curl -X POST localhost:8024/token \
  -d "grant_type=refresh_token&client_id=demo-web&client_secret=s3cr3t-demo-web&refresh_token=<refresh_token>"

# 5) 撤銷 refresh_token
curl -X POST localhost:8024/revoke -d "refresh_token=<refresh_token>"
```

## 內建 client（測試帳號）

| 欄位 | 值 |
|---|---|
| client_id | `demo-web` |
| client_secret | `s3cr3t-demo-web` |
| redirect_uri | `http://localhost:8024/callback` |
| 允許 scope | `openid profile email` |

## 檔案

```
src/Jwt.php         HS256 風格 JWT：base64url 自寫、hash_hmac 簽/驗、exp 過期檢查
src/AuthServer.php  client 註冊表 + 授權碼(一次性) + refresh(輪替) + 撤銷名單 + 驗 Bearer
public/index.php    路由 /authorize /token /revoke /api/me + 一鍵示範頁
data/               執行期產生（已 gitignore）：auth_codes / refresh_tokens / revoked
```

## ⚠️ 誠實聲明：HS256 取代 RS256

本機只有 `json + PDO + hash` 擴充，**無 openssl**，故 JWT 簽章用對稱式
`hash_hmac('sha256', ..., $secret, true)`（HS256）取代正式 OIDC 常見的非對稱式
RS256。base64url 自寫（`strtr` + `rtrim '='`）。

**簽章/驗章/過期、授權碼一次性、redirect_uri 比對、refresh 輪替、撤銷名單、
Bearer 驗證等授權邏輯皆為真實可執行**——差別只在金鑰是「共享密鑰」而非「公私鑰對」。
要換成 RS256 只需把 `Jwt` 的 `hash_hmac` 換成 `openssl_sign` / `openssl_verify`，
其餘流程完全不變（取捨見 notes 第 8 段）。
