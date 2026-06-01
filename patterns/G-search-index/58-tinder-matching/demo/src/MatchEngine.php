<?php
declare(strict_types=1);

/**
 * MatchEngine —— 交友配對核心（候選產生 + 配對偵測）
 * --------------------------------------------------
 * 組合兩個考點：附近的人(⑱ geohash) + 推薦(㊸ 召回→排序)。
 *
 * 候選堆疊產生流程（呼應推薦系統的「召回 → 過濾 → 排序」）：
 *   1) 召回：用 geohash 取「中心格 + 8 鄰格」找地理就近的人（候選池）。
 *   2) 雙向偏好過濾：
 *        - 我要看的：對方性別 ∈ 我的 pref_gender、年齡 ∈ 我的[age_min,age_max]、距離 ≤ 我的 max_km
 *        - 對方也要願意看我（雙向）：我性別/年齡符合對方偏好（避免無效曝光）
 *   3) 去重：看過 / 已滑過的不再出現（呼應爬蟲去重 frontier）。
 *   4) 排序：提升配對率 —— 「對方已經 like 過我」的排最前（互相喜歡機率最高），
 *            其次距離近者優先。
 *
 * 把介面（candidates / swipe）固定住，要換成生產級（Redis GEO 召回 +
 * 雙塔向量 ANN + 排序模型）只需替換內部實作，呼叫端不變。
 */
final class MatchEngine
{
    public function __construct(
        private GeoHash $geo,
        private Store $store,
        private int $precision = 6
    ) {
    }

    /**
     * 取得某使用者的候選堆疊（可滑的卡）。
     * @return array{
     *   me: array,
     *   center_geohash: string,
     *   buckets_scanned: list<string>,
     *   recalled: int,
     *   candidates: list<array>
     * }
     */
    public function candidates(string $userId, int $limit = 20): array
    {
        $me = $this->store->user($userId);
        if ($me === null) {
            return ['me' => [], 'center_geohash' => '', 'buckets_scanned' => [], 'recalled' => 0, 'candidates' => []];
        }

        // 1) 召回：geohash 中心格 + 8 鄰格
        $center = $this->geo->encode((float)$me['lat'], (float)$me['lng'], $this->precision);
        $cells  = $this->geo->neighbors($center);
        $buckets = $this->buildBuckets(); // geohash 前綴 → [userId,...]

        $seen     = $this->store->seenSet($userId); // 去重集合（含自己）
        $recalled = 0;
        $cands    = [];

        foreach ($cells as $cell) {
            foreach ($buckets[$cell] ?? [] as $otherId) {
                if (isset($seen[$otherId])) {
                    continue; // 3) 去重：看過/滑過的不再出現
                }
                $other = $this->store->user($otherId);
                if ($other === null) {
                    continue;
                }
                $recalled++;

                // 2) 雙向偏好過濾
                $dist = GeoHash::haversine(
                    (float)$me['lat'], (float)$me['lng'],
                    (float)$other['lat'], (float)$other['lng']
                );
                if (!$this->meWants($me, $other, $dist)) {
                    continue;
                }
                if (!$this->theyWant($other, $me, $dist)) {
                    continue;
                }

                // 對方是否已 like 我 → 互相喜歡機率最高
                $likesMe = $this->store->hasLiked($otherId, $userId);
                $cands[] = [
                    'id'          => $other['id'],
                    'name'        => $other['name'],
                    'gender'      => $other['gender'],
                    'age'         => $other['age'],
                    'distance_km' => round($dist, 2),
                    'likes_me'    => $likesMe,
                    'score'       => $this->score($likesMe, $dist),
                ];
            }
        }

        // 4) 排序：likes_me 優先、再距離近者優先（分數高在前）
        usort($cands, fn($a, $b) => $b['score'] <=> $a['score']);

        return [
            'me'              => $me,
            'center_geohash'  => $center,
            'buckets_scanned' => $cells,
            'recalled'        => $recalled,
            'candidates'      => array_slice($cands, 0, $limit),
        ];
    }

    /**
     * 滑卡。雙向 like 時觸發 match。
     * @param string $action like|pass
     * @return array{ok:bool, recorded:bool, matched:bool, match:?array, error?:string}
     */
    public function swipe(string $from, string $to, string $action): array
    {
        if ($this->store->user($from) === null || $this->store->user($to) === null) {
            return ['ok' => false, 'recorded' => false, 'matched' => false, 'match' => null, 'error' => '使用者不存在'];
        }
        if ($from === $to) {
            return ['ok' => false, 'recorded' => false, 'matched' => false, 'match' => null, 'error' => '不能滑自己'];
        }
        $r = $this->store->swipe($from, $to, $action);
        return ['ok' => true] + $r;
    }

    // ---------- 內部 ----------

    /** 建立 geohash 桶：精度 = $this->precision；同桶 = 地理鄰近 */
    private function buildBuckets(): array
    {
        $buckets = [];
        foreach ($this->store->users() as $u) {
            $gh = $this->geo->encode((float)$u['lat'], (float)$u['lng'], $this->precision);
            $buckets[$gh][] = $u['id'];
        }
        return $buckets;
    }

    /** 我想看對方嗎？（性別 / 年齡 / 距離） */
    private function meWants(array $me, array $other, float $dist): bool
    {
        if (!$this->genderOk($me['pref_gender'], $other['gender'])) {
            return false;
        }
        if ((int)$other['age'] < (int)$me['age_min'] || (int)$other['age'] > (int)$me['age_max']) {
            return false;
        }
        if ($dist > (float)$me['max_km']) {
            return false;
        }
        return true;
    }

    /** 對方也願意看我嗎？（雙向過濾，避免無效曝光） */
    private function theyWant(array $other, array $me, float $dist): bool
    {
        if (!$this->genderOk($other['pref_gender'], $me['gender'])) {
            return false;
        }
        if ((int)$me['age'] < (int)$other['age_min'] || (int)$me['age'] > (int)$other['age_max']) {
            return false;
        }
        if ($dist > (float)$other['max_km']) {
            return false;
        }
        return true;
    }

    /** pref: M/F/A(全部) 是否接受 gender */
    private function genderOk(string $pref, string $gender): bool
    {
        return $pref === 'A' || $pref === $gender;
    }

    /** 排序分數：對方已 like 我 → 大幅加權；距離越近分越高 */
    private function score(bool $likesMe, float $dist): float
    {
        $base = $likesMe ? 1000.0 : 0.0;
        return $base + (100.0 - min(100.0, $dist)); // 距離近 → 加分
    }
}
