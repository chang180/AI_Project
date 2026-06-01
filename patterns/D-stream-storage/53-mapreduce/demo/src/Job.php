<?php
declare(strict_types=1);

require_once __DIR__ . '/Splitter.php';
require_once __DIR__ . '/Mapper.php';
require_once __DIR__ . '/Shuffler.php';
require_once __DIR__ . '/Reducer.php';

/**
 * Job 排程器（模擬 master）
 * --------------------------------------------------
 * 在單機上「模擬」多個 map / reduce task 平行執行，串起三階段：
 *   輸入 → split → map（每片 (詞,1)）→ shuffle（依詞分組、partition 給 reducer）→ reduce（加總）
 *
 * 為了教學，每一階段的「中間產物」都會被保留並回傳，讓前端攤開來看：
 *   - splits：輸入被切成哪幾片
 *   - mapOutputs：每個 map task 各自吐出哪些 (詞,1)
 *   - partitions：shuffle 後，每個 reducer 收到哪些詞（同詞匯到同 reducer）
 *   - reduceOutputs：每個 reducer 的加總結果
 *   - result：合併後的全域 word count
 *
 * 真實系統：master 負責切分 task、指派 worker、監控心跳、
 * 重跑失敗 task、對落後者（straggler）做 speculative execution。
 * 這裡用單機迴圈忠實重現三階段邏輯與 partition 規則。
 */
final class Job
{
    private string $stateFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->stateFile = $dataDir . '/last_job.json';
    }

    /**
     * 跑一個 word count job，回傳三階段完整中間產物。
     *
     * @return array<string, mixed>
     */
    public function run(string $text, int $numMappers = 3, int $numReducers = 2, bool $combiner = false): array
    {
        $numMappers = max(1, min($numMappers, 16));
        $numReducers = max(1, min($numReducers, 8));

        // ── 1) 輸入切片 ───────────────────────────────
        $splits = Splitter::split($text, $numMappers);

        // ── 2) Map（模擬多個 map task 平行）────────────
        $mapOutputs = [];          // 每個 map task 的中間對
        $allPairs = [];            // 匯整給 shuffle
        foreach ($splits as $i => $split) {
            $pairs = Mapper::map($split, $combiner);
            $mapOutputs[$i] = $pairs;
            foreach ($pairs as $p) {
                $allPairs[] = $p;
            }
        }

        // ── 3) Shuffle / Sort（依詞分組 + partition）───
        $sh = Shuffler::shuffle($allPairs, $numReducers);
        $partitions = $sh['partitions'];
        $assignment = $sh['assignment'];

        // ── 4) Reduce（模擬多個 reduce task 平行）──────
        $reduceOutputs = [];       // 每個 reducer 的加總
        $result = [];              // 合併後全域結果
        foreach ($partitions as $r => $partition) {
            $reduced = Reducer::reduce($partition);
            $reduceOutputs[$r] = $reduced;
            foreach ($reduced as $word => $cnt) {
                $result[$word] = $cnt;   // 不同 reducer 負責的詞不重疊，直接併
            }
        }
        arsort($result);           // 結果依次數由高到低，好讀

        $summary = [
            'numMappers'      => count($splits),
            'numReducers'     => $numReducers,
            'combiner'        => $combiner,
            'totalTokens'     => array_sum($result),
            'distinctWords'   => count($result),
            'intermediatePairs' => count($allPairs),
        ];

        $state = [
            'input'         => $text,
            'summary'       => $summary,
            'splits'        => $splits,
            'mapOutputs'    => $mapOutputs,
            'partitions'    => $partitions,
            'assignment'    => $assignment,
            'reduceOutputs' => $reduceOutputs,
            'result'        => $result,
            'ranAt'         => gmdate('c'),
        ];

        $this->save($state);
        return $state;
    }

    /** 讀回上次 job 狀態（供 GET /api/state）。 */
    public function last(): ?array
    {
        if (!file_exists($this->stateFile)) {
            return null;
        }
        $json = (string) file_get_contents($this->stateFile);
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function save(array $state): void
    {
        $fp = fopen($this->stateFile, 'c+');
        if ($fp === false) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
