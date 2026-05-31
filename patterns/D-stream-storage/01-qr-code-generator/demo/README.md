# QR Code 生成器 · PHP Demo

聚焦系統設計考點：**短碼發號（KGS）→ 對映儲存 → 行內快取 → 轉址 → 掃描計數**。

## 啟動

```bash
cd demo
php -S localhost:8000 -t public
```

開瀏覽器 <http://localhost:8000>，輸入網址建立 QR，再點「轉址」測試。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 測試頁 |
| POST | `/api/qr` | 建立 `{ target_url }` → 回短碼 |
| GET | `/{code}` | 掃描轉址（302）+ 計數 |
| GET | `/qr/{code}.svg` | QR 圖片（示意） |
| GET | `/api/qr/{code}/stats` | 掃描數 + 快取命中率 |

## API 範例

```bash
curl -X POST localhost:8000/api/qr -d "target_url=https://example.com"
curl localhost:8000/api/qr/<code>/stats
```

## 檔案

```
src/ShortCode.php   base62 發號 + 乘法雜湊打亂（不可預測短碼）
src/Store.php       JSON 檔模擬 DB + 行內快取 + KGS 計數器（檔案鎖）
src/QrRenderer.php  SVG 方格示意圖
public/index.php    路由 + 測試頁
data/               執行期產生（已 gitignore）
```

## ⚠️ QR 圖片是示意，要產生真正可掃描的 QR？

`QrRenderer` 產生的是「像 QR 的方格圖」，非真正編碼。換成真套件只需兩步：

```bash
composer require endroid/qr-code
```

```php
use Endroid\QrCode\Builder\Builder;
$result = Builder::create()->data($base.'/'.$code)->size(300)->build();
header('Content-Type: '.$result->getMimeType());
echo $result->getString();
```

系統邏輯（短碼、快取、轉址、計數）完全不變。
