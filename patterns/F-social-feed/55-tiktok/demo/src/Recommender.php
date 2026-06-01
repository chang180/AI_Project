<?php
declare(strict_types=1);

/**
 * 推薦器 — 「For You」推薦流的核心：召回 → 排序 → 去重 → Top-N
 * ============================================================
 * 這是本題真正的考點（綜合 YouTube 轉碼分發 + 推薦排序 + Feed 分頁），不是 CRUD。
 *
 * 一頁 For You 怎麼產生（recommend）：
 *   1. 召回（candidate generation）：從幾百萬支影片裡，用「多路召回」撈出幾百個候選。
 *        - 興趣路：撈跟使用者偏好標籤相符的影片。
 *        - 熱門路：撈全站熱門影片（涵蓋面廣，也是冷啟動的後備）。
 *        - 協同路（簡化）：撈「跟我看過同片的人也愛看的」（這裡用標籤共現近似）。
 *      召回階段要快、要廣、可漏抓但不能太貴 → 只取候選，不精算。
 *   2. 排序（ranking）：對候選用「特徵」打分，精挑細排。
 *        分數 = 相符度(興趣) × w1 + 熱度 × w2 + 新鮮度 × w3。
 *        真實系統這一層是個機器學習模型（預估「看完率 / 互動率」）；
 *        這裡用可解釋的加權線性分數模擬，邏輯位置完全相同。
 *   3. 去重：把「本輪 session 已看過的」剔除 → 取下一頁時不會重複。
 *   4. 探索 vs 利用（explore/exploit）：大多給高分（利用既有興趣），
 *      但保留少量名額給「沒看過的新標籤 / 新片」（探索），避免同溫層、給新片曝光機會。
 *   5. Top-N：截斷取最高分的一頁；下一頁就是再呼叫一次（已看的被去重，分數更貼興趣）。
 *
 * 預載：回一頁的同時，順手算出「下一頁」放在 next_page，前端可預先載入降低延遲。
 */
final class Recommender
{
    /** 排序特徵權重（真實系統由模型學出來；這裡寫死，方便觀察） */
    public const W_RELEVANCE = 3.0;   // 與興趣相符度（個人化主訊號）
    public const W_POPULARITY = 0.004; // 熱度（縮放後當配角，避免熱門片壓過個人化）
    public const W_FRESHNESS = 0.5;   // 新鮮度（新片加分）

    /** 每頁幾支 */
    public const PAGE_SIZE = 5;

    /** 探索名額：每頁保留幾支給「使用者沒接觸過的標籤 / 新片」 */
    public const EXPLORE_SLOTS = 1;

    public function __construct(private VideoStore $store)
    {
    }

    /**
     * 取某使用者的一頁 For You（召回 → 排序 → 去重 → Top-N），並附帶下一頁（預載）。
     * @return array{user_id:string,page:array,next_page:array,debug:array}
     */
    public function recommend(string $userId, int $pageSize = self::PAGE_SIZE): array
    {
        $user = $this->store->getUser($userId);
        $interests = (array) $user['interests'];
        $watched = (array) $user['watched'];

        // ---- 1) 召回：多路撈候選 ----
        $recall = $this->recall($userId, $interests);

        // ---- 2) 排序：對候選打分 ----
        $scored = [];
        foreach ($recall['candidates'] as $vid => $video) {
            $scored[$vid] = $this->score($video, $interests);
        }

        // ---- 3) 去重：剔除本輪已看 ----
        $available = [];
        foreach ($recall['candidates'] as $vid => $video) {
            if (!in_array($vid, $watched, true)) {
                $available[$vid] = $video + ['_score' => $scored[$vid]];
            }
        }

        // 依分數排序（高 → 低）
        uasort($available, fn(array $a, array $b): int => $b['_score']['total'] <=> $a['_score']['total']);

        // ---- 4) 探索 vs 利用：取一頁 ----
        $page = $this->pickWithExploration(array_values($available), $interests, $pageSize);

        // ---- 5) 預載下一頁：把這頁也視為「已看」後再取一次 ----
        $pageIds = array_column($page, 'id');
        $nextPage = [];
        foreach ($available as $vid => $video) {
            if (!in_array($vid, $pageIds, true)) {
                $nextPage[] = $video;
                if (count($nextPage) >= $pageSize) {
                    break;
                }
            }
        }

        return [
            'user_id' => $userId,
            'page' => $page,
            'next_page' => $nextPage,           // 預載：前端可先抓，捲到底瞬間顯示
            'debug' => [
                'interests' => $interests,
                'watched_count' => count($watched),
                'recall_breakdown' => $recall['breakdown'],
                'candidate_count' => count($recall['candidates']),
                'cold_start' => empty($interests),  // 沒興趣訊號 → 靠熱門/探索冷啟動
            ],
        ];
    }

    /**
     * 多路召回：興趣路 + 熱門路 + 協同路（簡化），合併去重成候選池。
     * @return array{candidates:array<string,array>,breakdown:array}
     */
    private function recall(string $userId, array $interests): array
    {
        $all = $this->store->allVideos();
        $candidates = [];
        $breakdown = ['interest' => [], 'popular' => [], 'collaborative' => []];

        // (a) 興趣路：標籤命中使用者偏好的影片
        $likedTags = array_keys($interests);
        foreach ($all as $v) {
            if (array_intersect($v['tags'], $likedTags)) {
                $candidates[$v['id']] = $v;
                $breakdown['interest'][] = $v['id'];
            }
        }

        // (b) 熱門路：全站最熱前 N（涵蓋面 + 冷啟動後備）
        $byPop = $all;
        usort($byPop, fn(array $a, array $b): int => $b['popularity'] <=> $a['popularity']);
        foreach (array_slice($byPop, 0, 8) as $v) {
            if (!isset($candidates[$v['id']])) {
                $candidates[$v['id']] = $v;
            }
            $breakdown['popular'][] = $v['id'];
        }

        // (c) 協同路（簡化）：找「使用者看過的影片」其標籤，撈共享標籤的其他影片
        //     近似「看過同片的人也愛看的」（真實系統用協同過濾 / 向量近鄰）。
        $watchedTags = [];
        foreach ($this->store->watchedSet($userId) as $wid) {
            $wv = $this->store->getVideo($wid);
            if ($wv) {
                $watchedTags = array_merge($watchedTags, $wv['tags']);
            }
        }
        $watchedTags = array_values(array_unique($watchedTags));
        if ($watchedTags) {
            foreach ($all as $v) {
                if (array_intersect($v['tags'], $watchedTags)) {
                    if (!isset($candidates[$v['id']])) {
                        $candidates[$v['id']] = $v;
                    }
                    $breakdown['collaborative'][] = $v['id'];
                }
            }
        }

        return ['candidates' => $candidates, 'breakdown' => $breakdown];
    }

    /**
     * 排序特徵打分：相符度 / 熱度 / 新鮮度。
     * 相符度 = 影片標籤命中的興趣分數總和。
     * 新鮮度 = 越新（age_hours 越小）越高。
     */
    private function score(array $video, array $interests): array
    {
        $relevance = 0.0;
        foreach ($video['tags'] as $tag) {
            $relevance += (float) ($interests[$tag] ?? 0);
        }
        $popularity = (float) $video['popularity'];
        // 新鮮度：48 小時內線性遞減，之後趨近 0
        $freshness = max(0.0, 1.0 - ($video['age_hours'] / 48.0));

        $total = self::W_RELEVANCE * $relevance
            + self::W_POPULARITY * $popularity
            + self::W_FRESHNESS * $freshness;

        return [
            'relevance' => round($relevance, 3),
            'popularity' => $popularity,
            'freshness' => round($freshness, 3),
            'total' => round($total, 3),
        ];
    }

    /**
     * 探索 vs 利用：大多取高分（利用），但保留 EXPLORE_SLOTS 名額給
     * 「使用者沒接觸過的標籤」的影片（探索），打破同溫層、給新內容曝光。
     * @param array<int,array> $sortedAvailable 已依分數排序、已去重的候選
     */
    private function pickWithExploration(array $sortedAvailable, array $interests, int $pageSize): array
    {
        $likedTags = array_keys($interests);
        $exploitCount = max(0, $pageSize - self::EXPLORE_SLOTS);

        $page = array_slice($sortedAvailable, 0, $exploitCount);
        $chosenIds = array_column($page, 'id');

        // 探索：從剩下的候選裡找「含使用者沒看過的標籤」者，補進探索名額
        foreach ($sortedAvailable as $v) {
            if (count($page) >= $pageSize) {
                break;
            }
            if (in_array($v['id'], $chosenIds, true)) {
                continue;
            }
            $hasNovelTag = (bool) array_diff($v['tags'], $likedTags);
            if ($hasNovelTag) {
                $v['_explore'] = true;          // 標記：這支是探索來的
                $page[] = $v;
                $chosenIds[] = $v['id'];
            }
        }

        // 名額還沒填滿（探索找不到新標籤）→ 用剩餘高分補齊
        foreach ($sortedAvailable as $v) {
            if (count($page) >= $pageSize) {
                break;
            }
            if (!in_array($v['id'], $chosenIds, true)) {
                $page[] = $v;
                $chosenIds[] = $v['id'];
            }
        }

        return $page;
    }
}
