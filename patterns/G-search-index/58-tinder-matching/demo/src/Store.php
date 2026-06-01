<?php
declare(strict_types=1);

/**
 * Store —— 使用者 / 滑卡 / 配對 的資料儲存（模擬 DB）
 * --------------------------------------------------
 * 對應真實元件：使用者主庫（Postgres / Dynamo）、滑卡寫入流（高頻 append-only）、
 *               配對表（雙向 like 偵測）、已看集合（去重）。
 *
 * 這裡用 JSON 檔當「DB」、flock 做原子寫，呈現核心存取模式：
 *   - users   ：使用者 + 位置(lat/lng) + 偏好(性別/年齡/距離)
 *   - swipes  ：每筆滑卡 (from → to, like|pass)  ← 高頻寫
 *   - matches ：互相 like 後產生的配對 (a,b 正規化排序)
 *
 * 「已看集合」不另存表，而是由 swipes 即時推導（看過/滑過的不再出現），
 * 對應真實系統的 bloom filter / Redis set 去重（呼應爬蟲 frontier 去重）。
 *
 * 注意：本機 PHP 未載入 mbstring，全程只用原生字串函式（id/性別皆 ASCII）。
 */
final class Store
{
    private string $usersFile;
    private string $swipesFile;
    private string $matchesFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->usersFile   = $dataDir . '/users.json';
        $this->swipesFile  = $dataDir . '/swipes.json';
        $this->matchesFile = $dataDir . '/matches.json';
        foreach ([$this->usersFile, $this->swipesFile, $this->matchesFile] as $f) {
            if (!file_exists($f)) {
                file_put_contents($f, $f === $this->usersFile ? '{}' : '[]');
            }
        }
    }

    // ---------- 使用者 ----------

    /**
     * 新增 / 覆寫使用者。
     * @param array{gender:string, age:int, lat:float, lng:float,
     *              pref_gender:string, age_min:int, age_max:int, max_km:float} $attrs
     */
    public function putUser(string $id, string $name, array $attrs): array
    {
        $rec = [
            'id'          => $id,
            'name'        => $name,
            'gender'      => (string)$attrs['gender'],
            'age'         => (int)$attrs['age'],
            'lat'         => (float)$attrs['lat'],
            'lng'         => (float)$attrs['lng'],
            'pref_gender' => (string)$attrs['pref_gender'], // 想看的性別：M/F/A(全部)
            'age_min'     => (int)$attrs['age_min'],
            'age_max'     => (int)$attrs['age_max'],
            'max_km'      => (float)$attrs['max_km'],
        ];
        $users       = $this->users();
        $users[$id]  = $rec;
        $this->write($this->usersFile, $users);
        return $rec;
    }

    public function user(string $id): ?array
    {
        $users = $this->users();
        return $users[$id] ?? null;
    }

    /** @return array<string,array> */
    public function users(): array
    {
        return $this->read($this->usersFile);
    }

    // ---------- 滑卡（高頻寫 append-only）----------

    /**
     * 記錄一筆滑卡。回傳是否觸發配對。
     * 配對偵測：用「A 對 B」「B 對 A」兩筆判斷 —— 若雙方互相 like → match。
     * @param string $action like|pass
     * @return array{recorded:bool, matched:bool, match:?array}
     */
    public function swipe(string $from, string $to, string $action): array
    {
        $action = $action === 'like' ? 'like' : 'pass';
        $swipes = $this->swipes();

        // 同一對 (from→to) 已滑過則不重覆記錄（冪等）
        foreach ($swipes as $s) {
            if ($s['from'] === $from && $s['to'] === $to) {
                return ['recorded' => false, 'matched' => false, 'match' => null];
            }
        }
        $swipes[] = ['from' => $from, 'to' => $to, 'action' => $action, 'ts' => time()];
        $this->write($this->swipesFile, $swipes);

        // 只有 like 才可能成對；檢查對方是否「之前已 like 我」
        $matched = false;
        $match   = null;
        if ($action === 'like' && $this->hasLiked($to, $from)) {
            $match   = $this->ensureMatch($from, $to);
            $matched = $match['created'];
        }
        return ['recorded' => true, 'matched' => $matched, 'match' => $match];
    }

    /** B 是否曾經 like 過 A（配對偵測的另一半） */
    public function hasLiked(string $from, string $to): bool
    {
        foreach ($this->swipes() as $s) {
            if ($s['from'] === $from && $s['to'] === $to && $s['action'] === 'like') {
                return true;
            }
        }
        return false;
    }

    /**
     * 已看 / 已滑過的對象集合（去重用）：凡 from=$user 的所有 to。
     * 真實系統用 bloom filter / Redis set；這裡即時推導。
     * @return array<string,bool>
     */
    public function seenSet(string $user): array
    {
        $seen = [$user => true]; // 不會看到自己
        foreach ($this->swipes() as $s) {
            if ($s['from'] === $user) {
                $seen[$s['to']] = true;
            }
        }
        return $seen;
    }

    /** @return list<array> $user 的所有滑卡紀錄 */
    public function swipesOf(string $user): array
    {
        $out = [];
        foreach ($this->swipes() as $s) {
            if ($s['from'] === $user) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** @return list<array> */
    public function swipes(): array
    {
        $j = $this->read($this->swipesFile);
        return is_array($j) ? array_values($j) : [];
    }

    // ---------- 配對 ----------

    /**
     * 確保 (a,b) 有一筆配對；用排序後的 pair key 去重，避免 A-B / B-A 兩筆。
     * @return array{pair:string, a:string, b:string, ts:int, created:bool}
     */
    private function ensureMatch(string $a, string $b): array
    {
        $pair    = $this->pairKey($a, $b);
        $matches = $this->matches();
        foreach ($matches as $m) {
            if ($m['pair'] === $pair) {
                return $m + ['created' => false];
            }
        }
        $rec = ['pair' => $pair, 'a' => min($a, $b), 'b' => max($a, $b), 'ts' => time()];
        $matches[] = $rec;
        $this->write($this->matchesFile, $matches);
        return $rec + ['created' => true];
    }

    /** @return list<array> $user 參與的所有配對 */
    public function matchesOf(string $user): array
    {
        $out = [];
        foreach ($this->matches() as $m) {
            if ($m['a'] === $user || $m['b'] === $user) {
                $other = $m['a'] === $user ? $m['b'] : $m['a'];
                $out[] = ['with' => $other, 'pair' => $m['pair'], 'ts' => $m['ts']];
            }
        }
        return $out;
    }

    /** @return list<array> */
    public function matches(): array
    {
        $j = $this->read($this->matchesFile);
        return is_array($j) ? array_values($j) : [];
    }

    /** 正規化的配對鍵（排序後串接，使 A-B 與 B-A 視為同一對） */
    private function pairKey(string $a, string $b): string
    {
        return $a < $b ? $a . '|' . $b : $b . '|' . $a;
    }

    // ---------- 底層讀寫 ----------

    private function read(string $file): array
    {
        $json = (string) file_get_contents($file);
        $j    = json_decode($json, true);
        return is_array($j) ? $j : [];
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
