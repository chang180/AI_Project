<?php
declare(strict_types=1);

/**
 * 時序資料庫（Time-Series DB）— Prometheus 核心模型的單機教學版
 * ----------------------------------------------------------------
 * 真實 Prometheus：本地 TSDB 以 block + WAL + 倒排索引儲存樣本，PromQL 引擎查詢，
 * 高基數（cardinality）是頭號敵人。這裡用 JSON 檔當持久層、檔案鎖保證多請求安全，
 * 把「series 識別 / 樣本追加 / rate / sum by / 告警狀態機」這些核心邏輯做成真實可跑。
 *
 * 名詞：
 *   - series（時間序列）：metric name + labels 唯一決定一條 series。
 *     例如 http_requests_total{method="GET",code="200"} 與 {method="POST",...} 是兩條不同 series。
 *   - sample（樣本）：(timestamp 毫秒, value) 一個資料點，append 到所屬 series。
 *   - counter：單調遞增（總請求數、總錯誤數）；遇重啟會「reset」歸零。
 *   - gauge：可上可下（記憶體用量、佇列長度）。
 */
final class Tsdb
{
    private string $dataDir;
    private string $dbFile;     // series + 樣本
    private string $alertFile;  // 告警狀態機

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->dataDir = $dataDir;
        $this->dbFile = $dataDir . '/tsdb.json';
        $this->alertFile = $dataDir . '/alerts.json';
        if (!file_exists($this->dbFile)) {
            file_put_contents($this->dbFile, json_encode(['series' => []]));
        }
        if (!file_exists($this->alertFile)) {
            file_put_contents($this->alertFile, json_encode(['rules' => []]));
        }
    }

    // ============ series 識別 ============

    /**
     * 把 name + 排序後 labels 序列化成唯一 series key。
     * 排序是關鍵：{a="1",b="2"} 與 {b="2",a="1"} 必須對到同一條 series。
     * 產出如：http_requests_total{code="200",method="GET"}
     */
    public function seriesKey(string $name, array $labels): string
    {
        ksort($labels);
        $parts = [];
        foreach ($labels as $k => $v) {
            $parts[] = $k . '="' . $v . '"';
        }
        return $parts === [] ? $name : $name . '{' . implode(',', $parts) . '}';
    }

    // ============ 寫入：append 樣本 ============

    /**
     * 對某條 series append 一個樣本 (ts 毫秒, value)。
     * type 為 counter 或 gauge；同一 series 第一次寫入決定其型別。
     */
    public function append(string $name, array $labels, float $value, int $tsMs, string $type = 'gauge'): string
    {
        $key = $this->seriesKey($name, $labels);
        $db = $this->read($this->dbFile);
        if (!isset($db['series'][$key])) {
            $db['series'][$key] = [
                'name' => $name,
                'labels' => $labels,
                'type' => $type,
                'samples' => [],
            ];
        }
        $db['series'][$key]['samples'][] = ['t' => $tsMs, 'v' => $value];
        // 樣本依時間排序（scrape 一般單調，但容錯）
        usort($db['series'][$key]['samples'], static fn($a, $b) => $a['t'] <=> $b['t']);
        $this->write($this->dbFile, $db);
        return $key;
    }

    // ============ scrape：解析 Prometheus 曝露格式 ============

    /**
     * 解析 target 暴露的 /metrics 文字（Prometheus exposition format），逐行轉成樣本。
     * 支援：
     *   # TYPE http_requests_total counter
     *   http_requests_total{method="GET",code="200"} 1027
     *   process_resident_memory_bytes 5.1e7
     * scrape 的時間戳由 TSDB「拉取當下」打上（pull 模型的精髓）。
     */
    public function scrapeText(string $text, int $tsMs): int
    {
        $types = [];   // name => counter|gauge（來自 # TYPE 行）
        $count = 0;
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line[0] === '#') {
                if (preg_match('/^#\s*TYPE\s+(\S+)\s+(counter|gauge)/i', $line, $m)) {
                    $types[$m[1]] = strtolower($m[2]);
                }
                continue; // 略過 HELP 與其他註解
            }
            // metric{labels} value  或  metric value
            if (!preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{[^}]*\})?\s+([-+]?[0-9.eE]+)\s*$/', $line, $m)) {
                continue;
            }
            $name = $m[1];
            $labels = isset($m[2]) && $m[2] !== '' ? $this->parseLabels($m[2]) : [];
            $value = (float) $m[3];
            $type = $types[$name] ?? 'gauge';
            $this->append($name, $labels, $value, $tsMs, $type);
            $count++;
        }
        return $count;
    }

    /** 解析 {method="GET",code="200"} → ['method'=>'GET','code'=>'200'] */
    private function parseLabels(string $block): array
    {
        $block = trim($block, '{}');
        $labels = [];
        if ($block === '') {
            return $labels;
        }
        // 逐對 key="value"，值用非貪婪
        if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*"([^"]*)"/', $block, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $pair) {
                $labels[$pair[1]] = $pair[2];
            }
        }
        return $labels;
    }

    // ============ 曝露格式輸出（/metrics）============

    /** 把目前所有 series 的「最新值」輸出成 Prometheus 曝露格式（供再被 scrape）。 */
    public function exposition(): string
    {
        $db = $this->read($this->dbFile);
        $out = [];
        $seenType = [];
        foreach ($db['series'] as $s) {
            $name = $s['name'];
            if (!isset($seenType[$name])) {
                $out[] = '# TYPE ' . $name . ' ' . ($s['type'] ?? 'gauge');
                $seenType[$name] = true;
            }
            $last = end($s['samples']);
            if ($last === false) {
                continue;
            }
            $out[] = $this->seriesKey($name, $s['labels']) . ' ' . $this->fmt((float) $last['v']);
        }
        return implode("\n", $out) . "\n";
    }

    // ============ 查詢 ============

    /** 列出所有 series（含型別與樣本數），給測試頁用。 */
    public function listSeries(): array
    {
        $db = $this->read($this->dbFile);
        $rows = [];
        foreach ($db['series'] as $key => $s) {
            $last = end($s['samples']);
            $rows[] = [
                'key' => $key,
                'name' => $s['name'],
                'labels' => $s['labels'],
                'type' => $s['type'] ?? 'gauge',
                'samples' => count($s['samples']),
                'last' => $last === false ? null : (float) $last['v'],
            ];
        }
        return $rows;
    }

    /** instant：某條 series 的最新值。 */
    public function instant(string $name, array $labels): ?float
    {
        $db = $this->read($this->dbFile);
        $key = $this->seriesKey($name, $labels);
        if (!isset($db['series'][$key])) {
            return null;
        }
        $last = end($db['series'][$key]['samples']);
        return $last === false ? null : (float) $last['v'];
    }

    /**
     * rate(metric[windowSec])：對 counter 取時間窗內「每秒平均變化率」。
     * = (窗內最後值 - 窗內第一值) / (對應時間差秒數)，並處理 counter reset。
     *
     * counter reset 處理：counter 應單調遞增，若相鄰樣本後值 < 前值，視為重啟歸零，
     * 把「下降量」補回（Prometheus 的 extrapolation 簡化版），避免 rate 變負或失真。
     *
     * 回傳每條符合 name 的 series 的 rate（依 labels 分組）。
     */
    public function rate(string $name, int $windowSec, int $nowMs): array
    {
        $db = $this->read($this->dbFile);
        $cutoff = $nowMs - $windowSec * 1000;
        $results = [];
        foreach ($db['series'] as $key => $s) {
            if ($s['name'] !== $name) {
                continue;
            }
            $win = array_values(array_filter($s['samples'], static fn($p) => $p['t'] >= $cutoff));
            if (count($win) < 2) {
                continue; // 窗內樣本不足，無法算速率
            }
            // 累加遞增量，遇 reset 補回
            $delta = 0.0;
            for ($i = 1; $i < count($win); $i++) {
                $diff = (float) $win[$i]['v'] - (float) $win[$i - 1]['v'];
                if ($diff < 0) {
                    // counter reset：把後值當作從 0 重新累計
                    $delta += (float) $win[$i]['v'];
                } else {
                    $delta += $diff;
                }
            }
            $dtSec = ((int) $win[count($win) - 1]['t'] - (int) $win[0]['t']) / 1000;
            if ($dtSec <= 0) {
                continue;
            }
            $results[$key] = [
                'labels' => $s['labels'],
                'rate' => $delta / $dtSec,
            ];
        }
        return $results;
    }

    /**
     * sum [by(label)]：跨 series 把 instant 值加總，可選依某 label 分組。
     * $by = null → 全部加成一個總和；$by = 'method' → 依 method 分組各自加總。
     */
    public function sumBy(string $name, ?string $by, int $nowMs): array
    {
        $db = $this->read($this->dbFile);
        $groups = [];
        foreach ($db['series'] as $s) {
            if ($s['name'] !== $name) {
                continue;
            }
            $last = end($s['samples']);
            if ($last === false) {
                continue;
            }
            $g = $by === null ? '(all)' : (string) ($s['labels'][$by] ?? '');
            $groups[$g] = ($groups[$g] ?? 0.0) + (float) $last['v'];
        }
        return $groups;
    }

    // ============ 告警規則 + 狀態機 ============

    /**
     * 註冊告警規則。expr 形如 "rate http_requests_total[60] > 5"。
     * forSec：條件需「持續」這麼久才從 pending 升為 firing。
     */
    public function addRule(string $alertName, string $expr, int $forSec): void
    {
        $a = $this->read($this->alertFile);
        $a['rules'][$alertName] = [
            'expr' => $expr,
            'for' => $forSec,
            'state' => 'inactive', // inactive / pending / firing
            'activeSinceMs' => null,
            'lastValue' => null,
        ];
        $this->write($this->alertFile, $a);
    }

    /**
     * 評估所有規則，推進狀態機：
     *   條件成立且原為 inactive → pending（記下 activeSince）
     *   pending 且已持續 >= for → firing
     *   條件不成立 → resolved（回 inactive）
     * 回傳每條規則的目前狀態快照。
     */
    public function evalRules(int $nowMs): array
    {
        $a = $this->read($this->alertFile);
        $snapshot = [];
        foreach ($a['rules'] as $alertName => &$rule) {
            [$ok, $value] = $this->evalExpr($rule['expr'], $nowMs);
            $rule['lastValue'] = $value;
            if ($ok) {
                if ($rule['state'] === 'inactive') {
                    $rule['state'] = 'pending';
                    $rule['activeSinceMs'] = $nowMs;
                } elseif ($rule['state'] === 'pending') {
                    $heldSec = ($nowMs - (int) $rule['activeSinceMs']) / 1000;
                    if ($heldSec >= (int) $rule['for']) {
                        $rule['state'] = 'firing';
                    }
                }
                // firing 維持 firing
            } else {
                $rule['state'] = 'inactive';
                $rule['activeSinceMs'] = null;
            }
            $snapshot[$alertName] = [
                'expr' => $rule['expr'],
                'for' => $rule['for'],
                'state' => $rule['state'],
                'value' => $value,
                'activeSinceMs' => $rule['activeSinceMs'],
            ];
        }
        unset($rule);
        $this->write($this->alertFile, $a);
        return $snapshot;
    }

    /**
     * 極簡 expr 求值：支援 "rate NAME[WINDOW] OP THRESHOLD" 與 "instant NAME{labels} OP THRESHOLD"。
     * 回傳 [是否成立, 取到的數值]。rate 取所有符合 series 的最大值來判斷。
     */
    private function evalExpr(string $expr, int $nowMs): array
    {
        if (!preg_match('/^\s*(rate|instant)\s+(.+?)\s*(>=|<=|>|<|==)\s*([-+]?[0-9.]+)\s*$/i', $expr, $m)) {
            return [false, null];
        }
        $fn = strtolower($m[1]);
        $operand = trim($m[2]);
        $op = $m[3];
        $threshold = (float) $m[4];

        $value = null;
        if ($fn === 'rate') {
            if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)\[(\d+)\]$/', $operand, $mm)) {
                $rates = $this->rate($mm[1], (int) $mm[2], $nowMs);
                foreach ($rates as $r) {
                    $value = $value === null ? $r['rate'] : max($value, $r['rate']);
                }
            }
        } else { // instant
            $name = $operand;
            $labels = [];
            if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{[^}]*\})?$/', $operand, $mm)) {
                $name = $mm[1];
                if (isset($mm[2]) && $mm[2] !== '') {
                    $labels = $this->parseLabels($mm[2]);
                }
            }
            $value = $this->instant($name, $labels);
        }

        if ($value === null) {
            return [false, null];
        }
        $ok = match ($op) {
            '>' => $value > $threshold,
            '<' => $value < $threshold,
            '>=' => $value >= $threshold,
            '<=' => $value <= $threshold,
            '==' => $value === $threshold,
            default => false,
        };
        return [$ok, $value];
    }

    // ============ 工具 ============

    /** 把浮點數印成乾淨字串（整數不帶小數點）。 */
    private function fmt(float $v): string
    {
        if ($v === floor($v) && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    }

    private function read(string $file): array
    {
        $fp = fopen($file, 'c+');
        if ($fp === false) {
            return [];
        }
        flock($fp, LOCK_SH);
        $json = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_decode($json, true) ?: [];
    }

    private function write(string $file, array $data): void
    {
        $fp = fopen($file, 'c+');
        if ($fp === false) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
