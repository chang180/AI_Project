<?php
declare(strict_types=1);

/**
 * 道路圖（Road Graph）
 * --------------------------------------------------
 * 節點 = 路口（含經緯度座標），邊 = 路段（雙向）。
 * 每條邊帶兩個「基礎權重」：
 *   - distance：公尺（由兩端座標的 haversine 距離算出，固定不變）
 *   - time    ：自由流通行秒數（distance / 限速）
 * 即時路況以「壅塞係數」乘上 time → 得到當下 ETA 用的權重。
 *
 * 真實系統：節點/邊規模可達數十億，存於分區式圖儲存 + 預處理索引（CH/CRP）。
 * 這裡用 JSON 檔當持久層、用陣列當記憶體圖，足以呈現路由演算法的核心。
 */
final class Graph
{
    private string $file;

    /** @var array<int,array{id:int,lat:float,lng:float}> */
    private array $nodes = [];
    /** @var array<int,array<int,array{distance:float,time:float,congestion:float}>> 鄰接表 from→to→邊 */
    private array $adj = [];

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->file = $dataDir . '/graph.json';
        if (!file_exists($this->file)) {
            $this->seedSampleCity();
            $this->save();
        } else {
            $this->load();
        }
    }

    // ---------------- 建構 ----------------

    public function addNode(int $id, float $lat, float $lng): void
    {
        $this->nodes[$id] = ['id' => $id, 'lat' => $lat, 'lng' => $lng];
        $this->adj[$id] ??= [];
    }

    /** 加一條雙向路段，speedKmh = 限速；time 由距離 / 限速算出 */
    public function addEdge(int $a, int $b, float $speedKmh = 50.0): void
    {
        if (!isset($this->nodes[$a]) || !isset($this->nodes[$b])) {
            return;
        }
        $dist = $this->haversine($a, $b);                 // 公尺
        $time = $dist / ($speedKmh * 1000 / 3600);        // 秒（自由流）
        $this->adj[$a][$b] = ['distance' => $dist, 'time' => $time, 'congestion' => 1.0];
        $this->adj[$b][$a] = ['distance' => $dist, 'time' => $time, 'congestion' => 1.0];
    }

    /** 完全以外部資料重建圖（POST /api/graph 用） */
    public function replace(array $nodes, array $edges): void
    {
        $this->nodes = [];
        $this->adj = [];
        foreach ($nodes as $n) {
            $this->addNode((int) $n['id'], (float) $n['lat'], (float) $n['lng']);
        }
        foreach ($edges as $e) {
            $this->addEdge((int) $e['a'], (int) $e['b'], (float) ($e['speed'] ?? 50.0));
        }
        $this->save();
    }

    // ---------------- 即時路況 ----------------

    /**
     * 設定某條路段的壅塞係數（1.0 = 順暢；2.5 = 嚴重壅塞，通行時間 ×2.5）。
     * 雙向都套用。回傳是否成功。
     */
    public function setCongestion(int $a, int $b, float $factor): bool
    {
        $factor = max(0.1, $factor);
        if (!isset($this->adj[$a][$b])) {
            return false;
        }
        $this->adj[$a][$b]['congestion'] = $factor;
        $this->adj[$b][$a]['congestion'] = $factor;
        $this->save();
        return true;
    }

    /** 一次套用多筆路況；resetFirst 先把所有邊回到 1.0 */
    public function applyTraffic(array $items, bool $resetFirst = false): int
    {
        if ($resetFirst) {
            foreach ($this->adj as $from => $tos) {
                foreach ($tos as $to => $_) {
                    $this->adj[$from][$to]['congestion'] = 1.0;
                }
            }
        }
        $n = 0;
        foreach ($items as $it) {
            if ($this->setCongestionNoSave((int) $it['a'], (int) $it['b'], (float) $it['factor'])) {
                $n++;
            }
        }
        $this->save();
        return $n;
    }

    private function setCongestionNoSave(int $a, int $b, float $factor): bool
    {
        $factor = max(0.1, $factor);
        if (!isset($this->adj[$a][$b])) {
            return false;
        }
        $this->adj[$a][$b]['congestion'] = $factor;
        $this->adj[$b][$a]['congestion'] = $factor;
        return true;
    }

    // ---------------- 查詢 ----------------

    /** @return array<int,array{id:int,lat:float,lng:float}> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function hasNode(int $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function node(int $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * 取得某節點的鄰居與「當下權重」。
     * metric = 'time' 時，回傳 time × congestion（即時 ETA 用）；
     * metric = 'distance' 時，回傳純距離（最短路用，不受路況影響）。
     * @return array<int,float> to => weight
     */
    public function neighbors(int $id, string $metric): array
    {
        $out = [];
        foreach ($this->adj[$id] ?? [] as $to => $e) {
            $out[$to] = $metric === 'distance'
                ? $e['distance']
                : $e['time'] * $e['congestion'];
        }
        return $out;
    }

    /** 給 A* 用的啟發式：節點到目標的「直線距離 / 最高限速」下界（秒）或純直線距離（公尺）。 */
    public function heuristic(int $from, int $goal, string $metric): float
    {
        $straight = $this->haversine($from, $goal); // 公尺
        if ($metric === 'distance') {
            return $straight; // 可採納：直線必不長於實際路徑
        }
        // time：用全域最高限速換算成「最樂觀通行秒數」，確保不高估 → 可採納
        $maxSpeedMs = 90.0 * 1000 / 3600; // 90 km/h 視為地圖上限速天花板
        return $straight / $maxSpeedMs;
    }

    /** 全部邊（給瓦片/前端畫線用），去重後回傳無向邊 */
    public function edges(): array
    {
        $seen = [];
        $out = [];
        foreach ($this->adj as $from => $tos) {
            foreach ($tos as $to => $e) {
                $key = min($from, $to) . '-' . max($from, $to);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'a' => $from, 'b' => $to,
                    'distance' => round($e['distance'], 1),
                    'time' => round($e['time'], 1),
                    'congestion' => $e['congestion'],
                ];
            }
        }
        return $out;
    }

    // ---------------- 地圖瓦片（slippy map 簡化） ----------------

    /**
     * 回傳落在 (z,x,y) 瓦片經緯範圍內的節點與其相連邊。
     * 用標準 Web Mercator 瓦片公式把 tile 座標換回經緯邊界。
     */
    public function tile(int $z, int $x, int $y): array
    {
        [$lngW, $latN] = $this->tile2lonlat($x, $y, $z);
        [$lngE, $latS] = $this->tile2lonlat($x + 1, $y + 1, $z);

        $inBox = static fn(float $lat, float $lng): bool =>
            $lat <= $latN && $lat >= $latS && $lng >= $lngW && $lng <= $lngE;

        $nodeIds = [];
        $nodesOut = [];
        foreach ($this->nodes as $id => $n) {
            if ($inBox($n['lat'], $n['lng'])) {
                $nodeIds[$id] = true;
                $nodesOut[] = $n;
            }
        }
        $edgesOut = [];
        foreach ($this->edges() as $e) {
            if (isset($nodeIds[$e['a']]) || isset($nodeIds[$e['b']])) {
                $edgesOut[] = $e;
            }
        }
        return [
            'z' => $z, 'x' => $x, 'y' => $y,
            'bbox' => ['north' => $latN, 'south' => $latS, 'west' => $lngW, 'east' => $lngE],
            'nodes' => $nodesOut,
            'edges' => $edgesOut,
        ];
    }

    /** Web Mercator：tile (x,y,z) 左上角 → 經緯度 */
    private function tile2lonlat(int $x, int $y, int $z): array
    {
        $n = 2 ** $z;
        $lng = $x / $n * 360.0 - 180.0;
        $latRad = atan(self::sinh(M_PI * (1 - 2 * $y / $n)));
        $lat = $latRad * 180.0 / M_PI;
        return [$lng, $lat];
    }

    private static function sinh(float $x): float
    {
        return (exp($x) - exp(-$x)) / 2;
    }

    // ---------------- 幾何 ----------------

    /** 兩節點間 haversine 距離（公尺） */
    public function haversine(int $a, int $b): float
    {
        $na = $this->nodes[$a];
        $nb = $this->nodes[$b];
        $R = 6371000.0;
        $lat1 = $na['lat'] * M_PI / 180;
        $lat2 = $nb['lat'] * M_PI / 180;
        $dLat = ($nb['lat'] - $na['lat']) * M_PI / 180;
        $dLng = ($nb['lng'] - $na['lng']) * M_PI / 180;
        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }

    // ---------------- 持久化 ----------------

    private function load(): void
    {
        $json = (string) file_get_contents($this->file);
        $data = json_decode($json, true) ?: ['nodes' => [], 'adj' => []];
        foreach ($data['nodes'] as $n) {
            $this->nodes[(int) $n['id']] = [
                'id' => (int) $n['id'], 'lat' => (float) $n['lat'], 'lng' => (float) $n['lng'],
            ];
        }
        foreach ($data['adj'] ?? [] as $from => $tos) {
            foreach ($tos as $to => $e) {
                $this->adj[(int) $from][(int) $to] = [
                    'distance' => (float) $e['distance'],
                    'time' => (float) $e['time'],
                    'congestion' => (float) ($e['congestion'] ?? 1.0),
                ];
            }
        }
        foreach (array_keys($this->nodes) as $id) {
            $this->adj[$id] ??= [];
        }
    }

    private function save(): void
    {
        $data = ['nodes' => array_values($this->nodes), 'adj' => $this->adj];
        file_put_contents(
            $this->file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * 預設範例：6×5 格狀「城市」道路網（30 個路口）。
     * 用真實緯度間距讓 haversine 有意義；橫向路是大道（限速 60），縱向是巷弄（限速 40）。
     */
    private function seedSampleCity(): void
    {
        $cols = 6;
        $rows = 5;
        $lat0 = 25.040;   // 起始緯度（台北附近）
        $lng0 = 121.530;  // 起始經度
        $step = 0.004;    // 約 400~440 公尺一格

        $id = static fn(int $r, int $c): int => $r * $cols + $c + 1;

        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $this->addNode($id($r, $c), $lat0 + $r * $step, $lng0 + $c * $step);
            }
        }
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                if ($c + 1 < $cols) {
                    $this->addEdge($id($r, $c), $id($r, $c + 1), 60.0); // 橫向大道
                }
                if ($r + 1 < $rows) {
                    $this->addEdge($id($r, $c), $id($r + 1, $c), 40.0); // 縱向巷弄
                }
            }
        }
    }
}
