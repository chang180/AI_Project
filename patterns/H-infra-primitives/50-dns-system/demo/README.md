# DNS 系統 · PHP Demo

聚焦系統設計考點：**階層遞迴解析（root→TLD→authoritative，回傳完整解析鏈）→ 解析快取（含 TTL，命中省去遞迴）→ 記錄型別 A/CNAME（CNAME 再跟一次）→ GeoDNS（依 client 區域回最近的 IP）**。

## 啟動

```bash
cd demo
php -S localhost:8050 -t public
```

開瀏覽器 <http://localhost:8050>：

1. **解析 `www.example.com`**（區域 tw）→ 看完整解析鏈：stub→recursive→root→TLD→authoritative，最後回 A。
2. **立刻再解析同一個** → 這次顯示 **快取命中**，省去整段遞迴（剩餘 TTL 會遞減）。
3. **把區域切成 `us` / `eu`** 再解析 `www.example.com` → **GeoDNS 回不同 IP**（tw=203.0.113.10、us=198.51.100.20、eu=192.0.2.30）。
4. **解析 `blog.example.com`** → 看權威回 **CNAME → www.example.com**，再跟一次拿到 A。
5. **新增一筆記錄**（A 或 CNAME），或加上 GeoDNS 三區 IP，再解析它。
6. **清空快取** → 下次解析又走完整遞迴。

## 路由

| 方法 | 路徑 | 說明 |
|---|---|---|
| GET | `/` | 深色測試頁 |
| GET | `/api/resolve?name=&client_region=` | 解析網域 → 回完整解析鏈 + 是否命中快取 |
| POST | `/api/record` | 新增記錄 `{ name, type(A\|CNAME), value, ttl, geo:{tw,us,eu} }` |
| GET | `/api/state` | 目前所有區域 + 快取狀態（含剩餘 TTL） |
| POST | `/api/flush` | 清空快取 |

## API 範例

```bash
# 第一次解析（走完整遞迴，cache_hit=false）
curl -s "localhost:8050/api/resolve?name=www.example.com&client_region=tw"

# 立刻再查一次（cache_hit=true，省去遞迴）
curl -s "localhost:8050/api/resolve?name=www.example.com&client_region=tw"

# GeoDNS：換區域回不同 IP
curl -s "localhost:8050/api/resolve?name=www.example.com&client_region=us"

# CNAME：再跟一次直到 A
curl -s "localhost:8050/api/resolve?name=blog.example.com&client_region=tw"

# 新增一筆 GeoDNS A 記錄
curl -X POST localhost:8050/api/record -H "Content-Type: application/json" \
  -d '{"name":"cdn.acme.io","type":"A","ttl":60,"geo":{"tw":"10.0.0.1","us":"10.0.0.2","eu":"10.0.0.3"}}'
```

## 檔案

```
src/Resolver.php       核心：階層遞迴(root→TLD→authoritative)、解析快取含 TTL、A/CNAME 再跟、GeoDNS、flock 原子更新
public/index.php       路由 + 深色測試頁（解析鏈視覺化 / 加記錄 / 區域切換 / 快取狀態）
data/                  執行期產生（zones.json 權威區域、cache.json resolver 快取）
```

## ⚠️ 示意 vs 真實

- **真實系統**：stub resolver（OS）→ recursive resolver（如 8.8.8.8，有快取）→ root（13 組 anycast）→ TLD 權威（`.com`）→ 該網域的 authoritative，靠 UDP/53（大封包 TCP）一層層問；GeoDNS / anycast 讓使用者就近命中最近的節點。
- **本 demo**：用記憶體 + JSON 檔模擬三層區域表，沒有真正的 DNS 封包與網路傳輸。

但 **階層遞迴解析（完整解析鏈）、解析快取與 TTL（命中省遞迴、過期重查）、A/CNAME 再跟一次、GeoDNS 依區域回不同 IP、改記錄即失效快取** 等 DNS 邏輯**完全是真實可執行的**。要上線只需把記憶體查表換成真正向 root/TLD/權威發送 DNS 查詢，解析與快取邏輯不變。
