<?php
declare(strict_types=1);

/**
 * 狀態儲存（模擬訂單 DB + 外送員狀態 DB）
 * --------------------------------------------------
 * 外送平台是「三邊市場」：食客（下單）、餐廳（備餐）、外送員（取送）。
 * 三類資料的讀寫特性不同：
 *   - 外送員「當前位置」是高頻覆寫的熱資料 → 真實系統放記憶體型地理索引（Redis GEO）。
 *   - 外送員「狀態」與「訂單」需強一致（防同一張單被兩個外送員搶）→ 放支援原子條件更新的 DB。
 * 這裡用 JSON 檔當「DB」，用檔案鎖（flock）達成跨請求原子性，
 * 重點呈現「條件更新（CAS）防止並發重複指派」與「訂單狀態機」的核心邏輯。
 */
final class Store
{
    private string $orderFile;
    private string $courierFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->orderFile   = $dataDir . '/orders.json';
        $this->courierFile = $dataDir . '/couriers.json';
        if (!file_exists($this->orderFile)) {
            file_put_contents($this->orderFile, '{}');
        }
        if (!file_exists($this->courierFile)) {
            file_put_contents($this->courierFile, '{}');
        }
    }

    // ---------------- 外送員 ----------------

    /** 外送員上線並報位置（初始 available） */
    public function upsertCourier(string $id, float $lat, float $lng, string $name): array
    {
        return $this->mutate($this->courierFile, function (array $db) use ($id, $lat, $lng, $name): array {
            $existed = $db[$id] ?? [];
            $db[$id] = [
                'courier_id' => $id,
                'name'       => $name !== '' ? $name : ($existed['name'] ?? $id),
                'lat'        => $lat,
                'lng'        => $lng,
                // 已存在的外送員保留其狀態（送單中報位置不該被重設回 available）
                'status'     => $existed['status'] ?? 'available',
                'order_id'   => $existed['order_id'] ?? null,
                'updated_at' => gmdate('c'),
            ];
            return $db;
        })[$id];
    }

    /** 模擬 GPS 串流：更新外送員當前位置（高頻覆寫；不改狀態） */
    public function updateLocation(string $id, float $lat, float $lng): bool
    {
        $ok = false;
        $this->mutate($this->courierFile, function (array $db) use ($id, $lat, $lng, &$ok): array {
            if (isset($db[$id])) {
                $db[$id]['lat'] = $lat;
                $db[$id]['lng'] = $lng;
                $db[$id]['updated_at'] = gmdate('c');
                $ok = true;
            }
            return $db;
        });
        return $ok;
    }

    /**
     * 原子條件更新（CAS）：僅當 status === $expected 時，才改為 $next 並綁定 order_id。
     * 回傳 true = 搶到（受影響 1 列）；false = 已被別人改走（受影響 0 列）。
     * 這是「防止同一張訂單被兩個外送員搶 / 同一個外送員被指派兩張單」的關鍵：
     * 整段在 flock 互斥區內完成（呼應 RoboTaxi ⑤ 的原子媒合）。
     */
    public function compareAndSetStatus(string $id, string $expected, string $next, ?string $orderId = null): bool
    {
        $won = false;
        $this->mutate($this->courierFile, function (array $db) use ($id, $expected, $next, $orderId, &$won): array {
            if (isset($db[$id]) && $db[$id]['status'] === $expected) {
                $db[$id]['status'] = $next;
                if ($orderId !== null) {
                    $db[$id]['order_id'] = $next === 'available' ? null : $orderId;
                }
                if ($next === 'available') {
                    $db[$id]['order_id'] = null;
                }
                $db[$id]['updated_at'] = gmdate('c');
                $won = true;
            }
            return $db;
        });
        return $won;
    }

    public function getCourier(string $id): ?array
    {
        $db = $this->readJson($this->courierFile);
        return $db[$id] ?? null;
    }

    /** @return array<string,array> */
    public function allCouriers(): array
    {
        return $this->readJson($this->courierFile);
    }

    // ---------------- 訂單 ----------------

    public function putOrder(string $id, array $order): void
    {
        $this->mutate($this->orderFile, function (array $db) use ($id, $order): array {
            $db[$id] = $order;
            return $db;
        });
    }

    public function getOrder(string $id): ?array
    {
        $db = $this->readJson($this->orderFile);
        return $db[$id] ?? null;
    }

    /**
     * 原子推進訂單狀態：僅當目前狀態 === $expected 才改成 $next（並可寫入額外欄位）。
     * 用於訂單生命週期狀態機，避免亂序 / 並發重複推進。
     * 回傳更新後的訂單（成功）或 null（狀態不符 / 不存在）。
     */
    public function advanceOrder(string $id, string $expected, string $next, array $extra = []): ?array
    {
        $result = null;
        $this->mutate($this->orderFile, function (array $db) use ($id, $expected, $next, $extra, &$result): array {
            if (isset($db[$id]) && $db[$id]['status'] === $expected) {
                $db[$id]['status'] = $next;
                $db[$id]['updated_at'] = gmdate('c');
                $db[$id]['history'][] = ['status' => $next, 'at' => gmdate('c')];
                foreach ($extra as $k => $v) {
                    $db[$id][$k] = $v;
                }
                $result = $db[$id];
            }
            return $db;
        });
        return $result;
    }

    /** @return array<string,array> */
    public function allOrders(): array
    {
        return $this->readJson($this->orderFile);
    }

    // ---------------- 內部：原子讀改寫 ----------------

    /**
     * 在 flock(LOCK_EX) 互斥區內「讀 → 套用 $fn → 寫」，
     * 模擬 DB 交易，使條件更新具原子性（並發請求序列化）。
     * @param callable(array):array $fn
     * @return array 套用後的全表
     */
    private function mutate(string $file, callable $fn): array
    {
        $fp = fopen($file, 'c+');
        flock($fp, LOCK_EX);
        $raw = '';
        while (!feof($fp)) {
            $raw .= fread($fp, 8192);
        }
        $db = json_decode($raw, true) ?: [];
        $db = $fn($db);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $db;
    }

    private function readJson(string $file): array
    {
        $json = (string) file_get_contents($file);
        return json_decode($json, true) ?: [];
    }
}
