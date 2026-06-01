<?php
declare(strict_types=1);

/**
 * 下游消費者（模擬 Kafka consumer → 搜尋索引 / 去正規化快取）
 * --------------------------------------------------
 * 職責：讀變更日誌、依 LSN「依序」套用到一份「衍生視圖」，達成最終一致。
 *
 * 衍生視圖（derived view）：
 *   這裡是一份「去正規化快取」——把來源表的列攤平成下游想要的形狀
 *   （例如搜尋索引文件）。真實系統可能是 Elasticsearch、Redis、數倉。
 *
 * 三大保證：
 *   1) 有序：嚴格依 LSN 由小到大套用（分區內有序）。
 *   2) at-least-once：上游可能重送（offset 還沒提交就崩潰 → 重放）。
 *   3) 冪等套用：用「已套用的最大 LSN」當去重關卡——
 *      凡 event.lsn <= applied_lsn 的事件一律跳過，重放也不會套錯。
 *      （另用主鍵 + LSN 比較，確保套用結果與「只看最後一筆」一致。）
 */
final class Consumer
{
    private string $viewFile;     // 衍生視圖（去正規化快取）
    private string $offsetFile;   // 消費位移（已套用的最大 LSN）

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->viewFile = $dataDir . '/derived_view.json';
        $this->offsetFile = $dataDir . '/consumer_offset.txt';
        if (!file_exists($this->viewFile)) {
            file_put_contents($this->viewFile, '{}');
        }
        if (!file_exists($this->offsetFile)) {
            file_put_contents($this->offsetFile, '0');
        }
    }

    public function appliedLsn(): int
    {
        return (int) trim((string) file_get_contents($this->offsetFile));
    }

    /** 衍生視圖內容（pk => 去正規化文件，含 _lsn 來源戳記） */
    public function view(): array
    {
        $json = (string) file_get_contents($this->viewFile);
        return json_decode($json, true) ?: [];
    }

    /**
     * 消費一批變更事件，依序冪等套用。
     * $events：通常來自 ChangeLog::since(appliedLsn())，但即使傳入「已套用過的」
     *          事件（重放）也安全——靠 LSN 去重。
     * 回傳統計：['applied'=>n, 'skipped'=>n, 'new_offset'=>lsn, 'steps'=>[...]]
     */
    public function consume(array $events): array
    {
        // 依 LSN 排序，確保依序套用（防止亂序投遞）
        usort($events, static fn($a, $b) => $a['lsn'] <=> $b['lsn']);

        $view = $this->view();
        $applied = $this->appliedLsn();
        $countApplied = 0;
        $countSkipped = 0;
        $steps = [];

        foreach ($events as $e) {
            $lsn = (int) $e['lsn'];

            // 冪等關卡 1：已套用過（offset 去重）→ 跳過，重放不會套錯
            if ($lsn <= $applied) {
                $countSkipped++;
                $steps[] = ['lsn' => $lsn, 'op' => $e['op'], 'pk' => $e['pk'], 'action' => 'skip(已套用)'];
                continue;
            }

            $pk = (string) $e['pk'];
            $op = (string) $e['op'];

            if ($op === 'd') {
                unset($view[$pk]);
                $action = 'delete';
            } else {
                // c / u：把 after 攤平成去正規化文件，記下來源 LSN
                $doc = $e['after'] ?? [];
                $doc['_lsn'] = $lsn; // 冪等關卡 2：來源戳記，重放時可比較
                $view[$pk] = $doc;
                $action = $op === 'c' ? 'insert' : 'update';
            }

            $applied = $lsn;       // 推進位移（即套用完才提交）
            $countApplied++;
            $steps[] = ['lsn' => $lsn, 'op' => $op, 'pk' => $e['pk'], 'action' => $action];
        }

        $this->writeView($view);
        file_put_contents($this->offsetFile, (string) $applied, LOCK_EX);

        return [
            'applied' => $countApplied,
            'skipped' => $countSkipped,
            'new_offset' => $applied,
            'steps' => $steps,
        ];
    }

    private function writeView(array $view): void
    {
        $fp = fopen($this->viewFile, 'c+');
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($view, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
