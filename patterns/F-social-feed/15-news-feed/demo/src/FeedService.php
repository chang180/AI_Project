<?php
declare(strict_types=1);

/**
 * Feed Service — 推 / 拉 / Hybrid Fan-out 的核心邏輯
 * --------------------------------------------------
 * 這是本題真正的考點，不是 CRUD。
 *
 * 發文（publish）：
 *   - 一律寫入貼文庫（單一真相來源）。
 *   - 若作者「非名人」→ fan-out on write（推）：對每個粉絲的 feed 快取寫入 post_id。
 *   - 若作者「是名人」→ 不推（避免一次發文寫爆千萬份 feed），留待讀時即時拉。
 *     （真實系統用 Kafka 把 fan-out 丟給背景 worker 非同步處理；這裡用同步呼叫模擬。）
 *
 * 讀 feed（buildFeed）= Hybrid：
 *   1. 取「已推進來」的內容（推來的，O(1) 取自己 feed 快取）。
 *   2. 即時「拉」我追蹤的名人之最新貼文（fan-out on read）。
 *   3. k 路合併 + 依 seq（時間序）排序，去重，截斷取最新 N 筆。
 *   每筆標記 source = "push"（推來的）或 "pull"（即時拉的），以呈現 hybrid 的兩條路。
 */
final class FeedService
{
    public function __construct(
        private SocialGraph $graph,
        private FeedStore $store,
    ) {
    }

    /**
     * 發文 → 依作者是否名人決定推或拉
     * 回傳：貼文紀錄 + fanout 策略 + 推給了哪些粉絲
     */
    public function publish(string $authorId, string $text): array
    {
        $this->graph->ensureUser($authorId);
        $post = $this->store->addPost($authorId, $text);

        $isCelebrity = $this->graph->isCelebrity($authorId);
        $pushedTo = [];

        if (!$isCelebrity) {
            // fan-out on write（推）：對每個粉絲寫入 feed 快取
            foreach ($this->graph->followersOf($authorId) as $follower) {
                $this->store->pushToFeed($follower, $post['post_id']);
                $pushedTo[] = $follower;
            }
        }
        // 名人：不推，post 只進貼文庫，留待讀時拉

        return [
            'post' => $post,
            'fanout' => $isCelebrity ? 'pull' : 'push',
            'author_is_celebrity' => $isCelebrity,
            'follower_count' => $this->graph->followerCount($authorId),
            'pushed_to' => $pushedTo,
        ];
    }

    /**
     * 讀 News Feed（Hybrid）：合併「推來的」與「即時拉名人」的貼文
     * 回傳：items（含 source 標記）、各段統計
     */
    public function buildFeed(string $userId, int $limit = 20): array
    {
        // 1) 推來的：直接取自己 feed 快取（O(1)）並 hydrate
        $pushedIds = $this->store->pushedFeedIds($userId);
        $pushedPosts = $this->store->hydrate($pushedIds);

        // 2) 即時拉：找出「我追蹤的名人」，當場取其最新貼文（fan-out on read）
        $following = $this->graph->followingOf($userId);
        $celebrities = array_values(array_filter(
            $following,
            fn(string $f): bool => $this->graph->isCelebrity($f)
        ));
        $pulledPosts = [];
        foreach ($celebrities as $celeb) {
            foreach ($this->store->latestPostsByAuthor($celeb, $limit) as $p) {
                $pulledPosts[] = $p;
            }
        }

        // 3) 合併、標記來源、去重（同一 post 以推來的為準）、依 seq 排序、截斷
        $merged = [];
        foreach ($pushedPosts as $p) {
            $merged[$p['post_id']] = $p + ['source' => 'push'];
        }
        foreach ($pulledPosts as $p) {
            if (!isset($merged[$p['post_id']])) {
                $merged[$p['post_id']] = $p + ['source' => 'pull'];
            }
        }
        $items = array_values($merged);
        usort($items, fn(array $a, array $b): int => $b['seq'] <=> $a['seq']); // k 路合併取全域最新
        $items = array_slice($items, 0, $limit);

        return [
            'user_id' => $userId,
            'items' => $items,
            'stats' => [
                'pushed_count' => count($pushedPosts),
                'pulled_count' => count($pulledPosts),
                'pulled_from_celebrities' => $celebrities,
                'following_count' => count($following),
            ],
        ];
    }

    /** 列出每位使用者目前被判定為推 or 拉（粉絲數 vs 門檻） */
    public function fanoutPlan(): array
    {
        $plan = [];
        foreach ($this->graph->allUsers() as $u) {
            $isCeleb = $this->graph->isCelebrity($u);
            $plan[] = [
                'user_id' => $u,
                'followers' => $this->graph->followerCount($u),
                'threshold' => SocialGraph::CELEBRITY_THRESHOLD,
                'strategy' => $isCeleb ? 'pull（名人，讀時拉）' : 'push（一般，發文時推）',
                'is_celebrity' => $isCeleb,
            ];
        }
        return $plan;
    }
}
