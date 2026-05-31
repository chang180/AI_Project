<?php
declare(strict_types=1);

/**
 * 狀態儲存（模擬司機狀態 DB + 行程 DB）
 * --------------------------------------------------
 * 真實系統：
 *   - 司機「當前位置」是高頻覆寫的熱資料 → 放記憶體型地理索引（Redis GEO）。
 *   - 司機「狀態」與「行程」需強一致 → 放支援原子條件更新的 DB。
 * 這裡用 JSON 檔當「DB」，用檔案鎖（flock）達成跨請求的原子性，
 * 重點呈現「條件更新（CAS）防止並發重複指派」的核心邏輯。
 */
final class Store
{
    private string $driverFile;
    private string $rideFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->driverFile = $dataDir . '/drivers.json';
        $this->rideFile   = $dataDir . '/rides.json';
        if (!file_exists($this->driverFile)) {
            file_put_contents($this->driverFile, '{}');
        }
        if (!file_exists($this->rideFile)) {
            file_put_contents($this->rideFile, '{}');
        }
    }

    // ---------------- 司機 ----------------

    /** 註冊 / 上線一台司機（初始 available） */
    public function registerDriver(string $id, float $lat, float $lng, string $plate): array
    {
        return $this->mutate($this->driverFile, function (array $db) use ($id, $lat, $lng, $plate): array {
            $db[$id] = [
                'driver_id' => $id,
                'plate'     => $plate,
                'lat'       => $lat,
                'lng'       => $lng,
                'status'    => 'available',
                'updated_at'=> gmdate('c'),
            ];
            return $db;
        })[$id];
    }

    /** 模擬 GPS 串流：更新司機當前位置（高頻覆寫；不改狀態） */
    public function updateLocation(string $id, float $lat, float $lng): bool
    {
        $ok = false;
        $this->mutate($this->driverFile, function (array $db) use ($id, $lat, $lng, &$ok): array {
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
     * 原子條件更新（CAS）：僅當 status === $expected 時，才改為 $next。
     * 回傳 true = 搶到（受影響 1 列）；false = 已被別人改走（受影響 0 列）。
     * 這是「防止同一台車被指派兩次」的關鍵：整段在 flock 互斥區內完成。
     */
    public function compareAndSetStatus(string $id, string $expected, string $next): bool
    {
        $won = false;
        $this->mutate($this->driverFile, function (array $db) use ($id, $expected, $next, &$won): array {
            if (isset($db[$id]) && $db[$id]['status'] === $expected) {
                $db[$id]['status'] = $next;
                $db[$id]['updated_at'] = gmdate('c');
                $won = true;
            }
            return $db;
        });
        return $won;
    }

    public function getDriver(string $id): ?array
    {
        $db = $this->readJson($this->driverFile);
        return $db[$id] ?? null;
    }

    /** @return array<string,array> */
    public function allDrivers(): array
    {
        return $this->readJson($this->driverFile);
    }

    // ---------------- 行程 ----------------

    public function putRide(string $id, array $ride): void
    {
        $this->mutate($this->rideFile, function (array $db) use ($id, $ride): array {
            $db[$id] = $ride;
            return $db;
        });
    }

    public function getRide(string $id): ?array
    {
        $db = $this->readJson($this->rideFile);
        return $db[$id] ?? null;
    }

    public function setRideStatus(string $id, string $status): bool
    {
        $ok = false;
        $this->mutate($this->rideFile, function (array $db) use ($id, $status, &$ok): array {
            if (isset($db[$id])) {
                $db[$id]['status'] = $status;
                $ok = true;
            }
            return $db;
        });
        return $ok;
    }

    /** @return array<string,array> */
    public function allRides(): array
    {
        return $this->readJson($this->rideFile);
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
