<?php
declare(strict_types=1);

/**
 * 持久化儲存（模擬 Metadata DB）
 * --------------------------------------------------
 * 真實系統：裝置位置存於支援空間索引的資料庫（PostGIS / Elasticsearch geo_point /
 * Redis GEO）；地震事件與投遞紀錄存於關聯式 / 時序庫。
 * 這裡用 JSON 檔當「DB」，聚焦呈現「裝置 / 事件 / 投遞紀錄」三類資料的關係。
 */
final class Store
{
    private string $devicesFile;
    private string $eventsFile;
    private string $deliveriesFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->devicesFile    = $dataDir . '/devices.json';
        $this->eventsFile     = $dataDir . '/events.json';
        $this->deliveriesFile = $dataDir . '/deliveries.json';
        foreach ([$this->devicesFile, $this->eventsFile, $this->deliveriesFile] as $f) {
            if (!file_exists($f)) {
                file_put_contents($f, '{}');
            }
        }
    }

    // ---------- 裝置 ----------

    /** 註冊 / 更新一台裝置（含座標，對應真實系統的空間索引寫入） */
    public function putDevice(array $device): void
    {
        $db = $this->read($this->devicesFile);
        $db[$device['device_id']] = $device;
        $this->write($this->devicesFile, $db);
    }

    /** @return array<string,array> 所有裝置（地理篩選的來源資料集） */
    public function allDevices(): array
    {
        return $this->read($this->devicesFile);
    }

    // ---------- 地震事件 ----------

    /** 寫入 / 覆蓋一個地震事件（event_id 為冪等鍵：修正/取消用同一 id 覆蓋） */
    public function putEvent(array $event): void
    {
        $db = $this->read($this->eventsFile);
        $db[$event['event_id']] = $event;
        $this->write($this->eventsFile, $db);
    }

    public function getEvent(string $eventId): ?array
    {
        $db = $this->read($this->eventsFile);
        return $db[$eventId] ?? null;
    }

    public function allEvents(): array
    {
        return $this->read($this->eventsFile);
    }

    // ---------- 投遞紀錄（at-least-once 去重用） ----------

    /**
     * 是否已成功投遞過？冪等鍵 = (event_id, device_id)。
     * 真實系統把這層放在裝置端或閘道，避免同一警報重複響。
     */
    public function isDelivered(string $eventId, string $deviceId): bool
    {
        $db = $this->read($this->deliveriesFile);
        $key = $eventId . '|' . $deviceId;
        return ($db[$key]['status'] ?? null) === 'sent';
    }

    /** 記錄一筆投遞結果（queued / sent / failed） */
    public function recordDelivery(string $eventId, string $deviceId, array $record): void
    {
        $db = $this->read($this->deliveriesFile);
        $key = $eventId . '|' . $deviceId;
        $db[$key] = $record + ['event_id' => $eventId, 'device_id' => $deviceId];
        $this->write($this->deliveriesFile, $db);
    }

    /** @return array<int,array> 某事件的所有投遞紀錄 */
    public function deliveriesForEvent(string $eventId): array
    {
        $db = $this->read($this->deliveriesFile);
        $out = [];
        foreach ($db as $rec) {
            if (($rec['event_id'] ?? null) === $eventId) {
                $out[] = $rec;
            }
        }
        return $out;
    }

    // ---------- 內部 ----------

    private function read(string $file): array
    {
        $json = (string) file_get_contents($file);
        return json_decode($json, true) ?: [];
    }

    private function write(string $file, array $data): void
    {
        file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
