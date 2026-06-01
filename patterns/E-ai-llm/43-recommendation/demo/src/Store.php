<?php
declare(strict_types=1);

/**
 * 推薦系統儲存層（模擬「互動記錄表 + 特徵存儲 + item 中繼資料」）
 * --------------------------------------------------
 * 真實系統：
 *   - 互動事件（曝光/點擊/購買）走串流（Kafka）寫入資料湖，離線批次訓練。
 *   - item-item 相似度、user/item 特徵、embedding 預先離線算好，
 *     線上服務只「讀」這些特徵存儲（feature store，如 Redis / 低延遲 KV）。
 * 這裡用 JSON 檔當「DB / 特徵存儲」，用 flock 保護併發寫入，
 * 以呈現「離線算特徵、線上讀特徵」的兩階段資料流。
 */
final class Store
{
    private string $dir;
    private string $itemsFile;
    private string $eventsFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dir = $dataDir;
        $this->itemsFile = $dataDir . '/items.json';
        $this->eventsFile = $dataDir . '/events.json';
        if (!file_exists($this->itemsFile)) {
            $this->seed();
        }
    }

    /** 預設塞入數個 item（含類別/熱門度/上架時間）與數個 user 的互動 */
    private function seed(): void
    {
        $now = time();
        $day = 86400;
        // item：id => {title, category, popularity(累積互動數示意), created_at}
        $items = [
            'i1' => ['id' => 'i1', 'title' => '無線藍牙耳機',   'category' => '3C',   'popularity' => 920, 'created_at' => $now - 200 * $day],
            'i2' => ['id' => 'i2', 'title' => '機械式鍵盤',     'category' => '3C',   'popularity' => 540, 'created_at' => $now - 120 * $day],
            'i3' => ['id' => 'i3', 'title' => '4K 顯示器',      'category' => '3C',   'popularity' => 780, 'created_at' => $now - 90 * $day],
            'i4' => ['id' => 'i4', 'title' => '人體工學椅',     'category' => '家具', 'popularity' => 410, 'created_at' => $now - 60 * $day],
            'i5' => ['id' => 'i5', 'title' => '升降桌',         'category' => '家具', 'popularity' => 330, 'created_at' => $now - 45 * $day],
            'i6' => ['id' => 'i6', 'title' => '咖啡手沖壺',     'category' => '生活', 'popularity' => 260, 'created_at' => $now - 30 * $day],
            'i7' => ['id' => 'i7', 'title' => '磨豆機',         'category' => '生活', 'popularity' => 190, 'created_at' => $now - 10 * $day],
            'i8' => ['id' => 'i8', 'title' => '降噪耳塞',       'category' => '3C',   'popularity' => 120, 'created_at' => $now - 5 * $day],
            'i9' => ['id' => 'i9', 'title' => '收納層架',       'category' => '家具', 'popularity' => 150, 'created_at' => $now - 15 * $day],
        ];
        $this->atomicWrite($this->itemsFile, $items);

        // 互動事件：每筆 {user, item, type, ts}
        // type 權重：view=1, click=2, buy=5（在 Recommender 中換算）
        $e = [];
        $add = function (string $u, string $i, string $t) use (&$e, $now) {
            $e[] = ['user' => $u, 'item' => $i, 'type' => $t, 'ts' => $now - random_int(0, 20) * 3600];
        };
        // u1：3C 重度（耳機/鍵盤/顯示器）
        $add('u1', 'i1', 'buy');  $add('u1', 'i2', 'click'); $add('u1', 'i3', 'view'); $add('u1', 'i2', 'view');
        // u2：3C + 一點生活（鍵盤/顯示器/降噪耳塞）
        $add('u2', 'i2', 'buy');  $add('u2', 'i3', 'click'); $add('u2', 'i8', 'view');
        // u3：家具（椅子/升降桌）
        $add('u3', 'i4', 'buy');  $add('u3', 'i5', 'click'); $add('u3', 'i5', 'view');
        // u4：生活（手沖壺/磨豆機）
        $add('u4', 'i6', 'buy');  $add('u4', 'i7', 'click');
        // u5：耳機 + 顯示器（與 u1 共現重疊，用來示範協同過濾）
        $add('u5', 'i1', 'click'); $add('u5', 'i3', 'buy');
        $this->atomicWrite($this->eventsFile, $e);
    }

    /** @return array<string,array> 所有 item（id => 中繼資料） */
    public function items(): array
    {
        return $this->read($this->itemsFile);
    }

    public function item(string $id): ?array
    {
        $items = $this->items();
        return $items[$id] ?? null;
    }

    /** @return list<array> 所有互動事件 */
    public function events(): array
    {
        return $this->read($this->eventsFile);
    }

    /** @return list<string> 所有出現過的 user id（去重） */
    public function users(): array
    {
        $u = [];
        foreach ($this->events() as $ev) {
            $u[$ev['user']] = true;
        }
        $list = array_keys($u);
        sort($list);
        return $list;
    }

    /** 記錄一筆互動，並把該 item 熱門度 +1（示意「回饋迴路」即時更新特徵） */
    public function addInteraction(string $user, string $item, string $type): void
    {
        $fp = fopen($this->eventsFile, 'c+');
        flock($fp, LOCK_EX);
        $raw = (string) stream_get_contents($fp);
        $events = $raw === '' ? [] : (json_decode($raw, true) ?: []);
        $events[] = ['user' => $user, 'item' => $item, 'type' => $type, 'ts' => time()];
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $this->encode($events));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        // 熱門度 +1（回饋迴路：使用者行為回灌特徵）
        $items = $this->items();
        if (isset($items[$item])) {
            $items[$item]['popularity'] = ($items[$item]['popularity'] ?? 0) + 1;
            $this->atomicWrite($this->itemsFile, $items);
        }
    }

    // ---------------- 內部 ----------------
    private function read(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }
        $json = (string) file_get_contents($file);
        return $json === '' ? [] : (json_decode($json, true) ?: []);
    }

    private function atomicWrite(string $file, array $data): void
    {
        file_put_contents($file, $this->encode($data), LOCK_EX);
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
