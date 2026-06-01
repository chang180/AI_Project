<?php
declare(strict_types=1);

require_once __DIR__ . '/ConsistentHashRing.php';
require_once __DIR__ . '/VectorClock.php';

/**
 * 多節點叢集模擬（在單一 PHP process 內）
 * --------------------------------------------------------------
 * 誠實聲明：這裡用「同一支程式 + 各節點一個 JSON 檔」模擬多台機器，
 * 沒有真實網路、沒有 gossip 定時心跳。但以下系統邏輯都是「真的」：
 *   - 一致性雜湊環 + vnode 決定 key 落點與 preference list
 *   - quorum 寫 W / 讀 R（W+R>N 保證讀寫副本集合重疊）
 *   - 向量時鐘版本、並發衝突偵測與 sibling
 *   - read repair（讀到落後副本時補寫）
 *   - hinted handoff（目標節點 down 時暫存到代理節點，回來再交還）
 *
 * 資料佈局（data/ 下，已 gitignore）：
 *   meta.json          叢集設定 N/R/W、節點 up/down 狀態、vnode 數
 *   node_<name>.json   該節點的 KV：key => [ {value, clock}, ... ]（陣列 = 可能多 sibling）
 *   hints_<name>.json  該節點代收的 hinted handoff：[ {forNode, key, value, clock}, ... ]
 */
final class Cluster
{
    private string $dir;
    private string $metaFile;
    private ConsistentHashRing $ring;

    /** @var array{N:int,R:int,W:int,vnodes:int,nodes:array<string,bool>} */
    private array $meta;

    public function __construct(string $dataDir)
    {
        $this->dir = rtrim($dataDir, '/\\');
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        $this->metaFile = $this->dir . '/meta.json';
        $this->loadMeta();
        $this->buildRing();
    }

    // ===================== 設定 / 節點管理 =====================

    private function defaultMeta(): array
    {
        return [
            'N' => 3,
            'R' => 2,
            'W' => 2,
            'vnodes' => 12,
            // 預設三節點，皆 up（true=up, false=down）
            'nodes' => ['A' => true, 'B' => true, 'C' => true],
        ];
    }

    private function loadMeta(): void
    {
        if (!file_exists($this->metaFile)) {
            $this->meta = $this->defaultMeta();
            $this->saveMeta();
            return;
        }
        $json = (string) file_get_contents($this->metaFile);
        $m = json_decode($json, true);
        $this->meta = is_array($m) ? $m : $this->defaultMeta();
    }

    private function saveMeta(): void
    {
        file_put_contents(
            $this->metaFile,
            json_encode($this->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function buildRing(): void
    {
        $this->ring = new ConsistentHashRing((int) $this->meta['vnodes']);
        foreach (array_keys($this->meta['nodes']) as $node) {
            $this->ring->addNode($node);   // 環包含所有節點（含 down 的，down 只影響讀寫可達性）
        }
    }

    public function config(): array
    {
        return [
            'N' => $this->meta['N'],
            'R' => $this->meta['R'],
            'W' => $this->meta['W'],
            'vnodes' => $this->meta['vnodes'],
            'nodes' => $this->meta['nodes'],
            'quorumOk' => ($this->meta['W'] + $this->meta['R'] > $this->meta['N']),
        ];
    }

    public function setConfig(int $n, int $r, int $w): void
    {
        $this->meta['N'] = max(1, $n);
        $this->meta['R'] = max(1, $r);
        $this->meta['W'] = max(1, $w);
        $this->saveMeta();
    }

    public function isUp(string $node): bool
    {
        return (bool) ($this->meta['nodes'][$node] ?? false);
    }

    /** add / remove / up / down */
    public function nodeAction(string $action, string $node): array
    {
        $node = trim($node);
        if ($node === '') {
            return ['ok' => false, 'msg' => '節點名稱不可空'];
        }
        switch ($action) {
            case 'add':
                if (isset($this->meta['nodes'][$node])) {
                    return ['ok' => false, 'msg' => "節點 $node 已存在"];
                }
                $this->meta['nodes'][$node] = true;
                $this->saveMeta();
                $this->buildRing();
                return ['ok' => true, 'msg' => "已加入節點 $node（環重平衡，只有相鄰 key 受影響）"];
            case 'remove':
                if (!isset($this->meta['nodes'][$node])) {
                    return ['ok' => false, 'msg' => "節點 $node 不存在"];
                }
                unset($this->meta['nodes'][$node]);
                $this->saveMeta();
                $this->buildRing();
                @unlink($this->nodeFile($node));
                return ['ok' => true, 'msg' => "已移除節點 $node"];
            case 'down':
                if (!isset($this->meta['nodes'][$node])) {
                    return ['ok' => false, 'msg' => "節點 $node 不存在"];
                }
                $this->meta['nodes'][$node] = false;
                $this->saveMeta();
                return ['ok' => true, 'msg' => "節點 $node 已下線（後續寫入走 hinted handoff 暫存到健康節點）"];
            case 'up':
                if (!isset($this->meta['nodes'][$node])) {
                    return ['ok' => false, 'msg' => "節點 $node 不存在"];
                }
                $this->meta['nodes'][$node] = true;
                $this->saveMeta();
                $delivered = $this->deliverHints($node);
                return ['ok' => true, 'msg' => "節點 $node 已上線，交還 hinted handoff $delivered 筆"];
            default:
                return ['ok' => false, 'msg' => "未知動作 $action"];
        }
    }

    // ===================== 環 / preference list 視圖 =====================

    public function ringView(): array
    {
        $points = [];
        foreach ($this->ring->ring() as $point => $node) {
            $points[] = ['point' => $point, 'node' => $node, 'up' => $this->isUp($node)];
        }
        return [
            'vnodes' => $this->meta['vnodes'],
            'nodes' => array_map(
                fn($n) => ['name' => $n, 'up' => $this->isUp($n)],
                $this->ring->nodes()
            ),
            'points' => $points,
        ];
    }

    /** key 的 preference list（前 N 個不同實體節點），標記每個的 up/down */
    public function preferenceList(string $key): array
    {
        $list = $this->ring->preferenceList($key, (int) $this->meta['N']);
        return array_map(fn($n) => ['node' => $n, 'up' => $this->isUp($n)], $list);
    }

    // ===================== 寫入 put（quorum W + hinted handoff） =====================

    /**
     * @return array{ok:bool,key:string,clock:array<string,int>,replicas:array,W:int,acks:int,msg:string}
     */
    public function put(string $key, string $value, ?string $coordinator = null): array
    {
        $N = (int) $this->meta['N'];
        $W = (int) $this->meta['W'];
        $prefList = $this->ring->preferenceList($key, $N);
        if ($prefList === []) {
            return ['ok' => false, 'key' => $key, 'clock' => [], 'replicas' => [], 'W' => $W, 'acks' => 0, 'msg' => '環上無節點'];
        }

        // 協調者：預設取 preference list 第一個健康節點；也可由呼叫端指定（用來製造並發寫）
        $coordinator = $coordinator ?: $this->firstHealthy($prefList) ?: $prefList[0];

        // 讀現有版本 → 取得合併後的因果基準（讀-修改-寫），再由協調者 +1
        $existing = $this->readReplicas($key, $prefList);
        $baseClock = [];
        foreach ($existing as $r) {
            $baseClock = VectorClock::merge($baseClock, $r['clock']);
        }
        $newClock = VectorClock::increment($baseClock, $coordinator);

        // 對 preference list 逐一寫入；down 節點 → 走 hinted handoff 暫存到下一個健康節點
        $replicas = [];
        $acks = 0;
        $handoffTargets = $this->healthyNodesOutside($prefList); // 候補健康節點（接手 hint）
        foreach ($prefList as $target) {
            if ($this->isUp($target)) {
                $this->writeReplica($target, $key, $value, $newClock);
                $acks++;
                $replicas[] = ['node' => $target, 'stored' => true, 'hinted' => false];
            } else {
                // hinted handoff：找一個健康節點代收，標記 forNode=target
                $proxy = array_shift($handoffTargets);
                if ($proxy !== null) {
                    $this->writeHint($proxy, $target, $key, $value, $newClock);
                    $acks++; // 代收也算一次成功寫（最終會交還）
                    $replicas[] = ['node' => $target, 'stored' => false, 'hinted' => true, 'proxy' => $proxy];
                } else {
                    $replicas[] = ['node' => $target, 'stored' => false, 'hinted' => false];
                }
            }
        }

        $ok = $acks >= $W;
        return [
            'ok' => $ok,
            'key' => $key,
            'coordinator' => $coordinator,
            'clock' => $newClock,
            'clockStr' => VectorClock::toString($newClock),
            'replicas' => $replicas,
            'W' => $W,
            'acks' => $acks,
            'msg' => $ok ? "寫入成功：$acks/$W 個副本 ack（W quorum 達成）"
                         : "寫入失敗：只有 $acks 個 ack，未達 W=$W",
        ];
    }

    // ===================== 讀取 get（quorum R + read repair + sibling） =====================

    /**
     * @return array
     */
    public function get(string $key): array
    {
        $N = (int) $this->meta['N'];
        $R = (int) $this->meta['R'];
        $prefList = $this->ring->preferenceList($key, $N);
        if ($prefList === []) {
            return ['ok' => false, 'key' => $key, 'msg' => '環上無節點', 'values' => []];
        }

        // 從健康副本讀，收集到 R 個回應（含「不存在」也算一次回應）
        $responses = [];     // [ ['node'=>, 'versions'=>[ ['value','clock'], ... ] ] ]
        $reachable = 0;
        foreach ($prefList as $node) {
            if (!$this->isUp($node)) {
                continue;
            }
            $versions = $this->readNodeKey($node, $key);
            $responses[] = ['node' => $node, 'versions' => $versions];
            $reachable++;
            if ($reachable >= $R) {
                break;
            }
        }

        if ($reachable < $R) {
            return [
                'ok' => false,
                'key' => $key,
                'R' => $R,
                'reachable' => $reachable,
                'msg' => "讀取失敗：只連到 $reachable 個健康副本，未達 R=$R",
                'values' => [],
            ];
        }

        // 把所有回應的所有版本攤平，做向量時鐘調和（reconcile）
        $allVersions = [];
        foreach ($responses as $resp) {
            foreach ($resp['versions'] as $v) {
                $allVersions[] = $v;
            }
        }
        $reconciled = $this->reconcile($allVersions); // 只保留無法互相比較（並發）的版本 = siblings

        // read repair：把落後/缺漏的副本補成 reconciled 結果
        $repaired = [];
        if ($reconciled !== []) {
            foreach ($responses as $resp) {
                $node = $resp['node'];
                if ($this->needsRepair($resp['versions'], $reconciled)) {
                    $this->overwriteNodeKey($node, $key, $reconciled);
                    $repaired[] = $node;
                }
            }
        }

        $values = array_map(
            fn($v) => ['value' => $v['value'], 'clock' => $v['clock'], 'clockStr' => VectorClock::toString($v['clock'])],
            $reconciled
        );

        return [
            'ok' => true,
            'key' => $key,
            'R' => $R,
            'reachable' => $reachable,
            'siblings' => count($values),
            'conflict' => count($values) > 1,
            'values' => $values,
            'readRepair' => $repaired,
            'msg' => count($values) === 0
                ? "key 不存在（讀了 $reachable 個副本）"
                : (count($values) > 1
                    ? "讀到 " . count($values) . " 個並發 sibling（向量時鐘衝突，需應用層合併）"
                    : "讀取成功（R quorum=$R 達成" . ($repaired ? "，read repair 修補 " . implode(',', $repaired) : '') . "）"),
        ];
    }

    /**
     * 向量時鐘調和：丟掉被別人 happens-before 的舊版本，只留下互為並發的版本。
     * @param array $versions  [ ['value','clock'], ... ]
     * @return array
     */
    private function reconcile(array $versions): array
    {
        $kept = [];
        foreach ($versions as $cand) {
            $dominated = false;
            foreach ($kept as $i => $k) {
                $cmp = VectorClock::compare($cand['clock'], $k['clock']);
                if ($cmp === VectorClock::EQUAL) {
                    $dominated = true; // 同一版本，去重
                    break;
                }
                if ($cmp === VectorClock::BEFORE) {
                    $dominated = true; // cand 較舊，丟掉
                    break;
                }
                if ($cmp === VectorClock::AFTER) {
                    unset($kept[$i]);  // cand 較新，淘汰舊的
                }
            }
            if (!$dominated) {
                $kept[] = $cand;
            }
        }
        return array_values($kept);
    }

    /** 該節點的版本集合是否已等於 reconciled（否則需 read repair） */
    private function needsRepair(array $nodeVersions, array $reconciled): bool
    {
        if (count($nodeVersions) !== count($reconciled)) {
            return true;
        }
        foreach ($reconciled as $rv) {
            $found = false;
            foreach ($nodeVersions as $nv) {
                if (VectorClock::compare($nv['clock'], $rv['clock']) === VectorClock::EQUAL
                    && $nv['value'] === $rv['value']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return true;
            }
        }
        return false;
    }

    // ===================== 低階：節點檔讀寫 =====================

    private function nodeFile(string $node): string
    {
        return $this->dir . '/node_' . preg_replace('/[^A-Za-z0-9_]/', '_', $node) . '.json';
    }

    private function hintFile(string $node): string
    {
        return $this->dir . '/hints_' . preg_replace('/[^A-Za-z0-9_]/', '_', $node) . '.json';
    }

    /** @return array<string,array> 整顆節點資料 key => versions[] */
    private function readNode(string $node): array
    {
        $f = $this->nodeFile($node);
        if (!file_exists($f)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($f), true);
        return is_array($data) ? $data : [];
    }

    private function writeNode(string $node, array $data): void
    {
        file_put_contents(
            $this->nodeFile($node),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /** @return array versions[] for one key on one node */
    private function readNodeKey(string $node, string $key): array
    {
        $data = $this->readNode($node);
        return $data[$key] ?? [];
    }

    /** 寫一個版本到某節點：與既有版本做向量時鐘調和後存回（用 flock 原子化） */
    private function writeReplica(string $node, string $key, string $value, array $clock): void
    {
        $f = $this->nodeFile($node);
        $fp = fopen($f, 'c+');
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $data = json_decode((string) $raw, true);
        $data = is_array($data) ? $data : [];

        $versions = $data[$key] ?? [];
        $versions[] = ['value' => $value, 'clock' => $clock];
        $data[$key] = $this->reconcile($versions); // 收斂，避免同節點堆積舊版

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /** 直接以 reconciled 版本覆寫某節點的某 key（read repair / hint 交還用） */
    private function overwriteNodeKey(string $node, string $key, array $versions): void
    {
        $data = $this->readNode($node);
        $data[$key] = $versions;
        $this->writeNode($node, $data);
    }

    /** 讀 preference list 上「健康」副本的所有版本（put 前取因果基準用） */
    private function readReplicas(string $key, array $prefList): array
    {
        $out = [];
        foreach ($prefList as $node) {
            if (!$this->isUp($node)) {
                continue;
            }
            foreach ($this->readNodeKey($node, $key) as $v) {
                $out[] = $v;
            }
        }
        return $this->reconcile($out);
    }

    // ===================== hinted handoff =====================

    private function firstHealthy(array $nodes): ?string
    {
        foreach ($nodes as $n) {
            if ($this->isUp($n)) {
                return $n;
            }
        }
        return null;
    }

    /** preference list 以外、目前健康的節點（可當 hint 代收者） */
    private function healthyNodesOutside(array $prefList): array
    {
        $set = array_flip($prefList);
        $out = [];
        foreach ($this->ring->nodes() as $n) {
            if (!isset($set[$n]) && $this->isUp($n)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    private function writeHint(string $proxy, string $forNode, string $key, string $value, array $clock): void
    {
        $f = $this->hintFile($proxy);
        $hints = [];
        if (file_exists($f)) {
            $hints = json_decode((string) file_get_contents($f), true) ?: [];
        }
        $hints[] = ['forNode' => $forNode, 'key' => $key, 'value' => $value, 'clock' => $clock];
        file_put_contents(
            $f,
            json_encode($hints, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /** 節點回來時，掃所有代理節點的 hint，把屬於它的交還，回傳交還筆數 */
    private function deliverHints(string $recovered): int
    {
        $delivered = 0;
        foreach ($this->ring->nodes() as $proxy) {
            $f = $this->hintFile($proxy);
            if (!file_exists($f)) {
                continue;
            }
            $hints = json_decode((string) file_get_contents($f), true) ?: [];
            $remain = [];
            foreach ($hints as $h) {
                if (($h['forNode'] ?? '') === $recovered) {
                    $this->writeReplica($recovered, $h['key'], $h['value'], $h['clock']);
                    $delivered++;
                } else {
                    $remain[] = $h;
                }
            }
            if ($remain === []) {
                @unlink($f);
            } else {
                file_put_contents(
                    $f,
                    json_encode($remain, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    LOCK_EX
                );
            }
        }
        return $delivered;
    }

    /** 目前所有節點的 hint 概況（供測試頁顯示） */
    public function hintsView(): array
    {
        $out = [];
        foreach ($this->ring->nodes() as $proxy) {
            $f = $this->hintFile($proxy);
            if (!file_exists($f)) {
                continue;
            }
            $hints = json_decode((string) file_get_contents($f), true) ?: [];
            foreach ($hints as $h) {
                $out[] = ['proxy' => $proxy, 'forNode' => $h['forNode'], 'key' => $h['key'], 'value' => $h['value']];
            }
        }
        return $out;
    }

    /** 重映射示範：列出一組 key 目前落點（加/移除節點前後可比對比例） */
    public function keyPlacement(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = (string) $this->ring->locate($k);
        }
        return $out;
    }
}
