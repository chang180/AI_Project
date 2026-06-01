<?php
declare(strict_types=1);

/**
 * For You 會話協調器 — 串起「取一頁 → 看一支 → 回饋更新興趣 → 取下一頁」的回饋迴路。
 * --------------------------------------------------
 * 取一頁（feed）：委派 Recommender 做召回→排序→去重→Top-N，並附下一頁（預載）。
 * 看一支（watch）：
 *   1. 記錄已看（同一輪 session 去重的依據）。
 *   2. 累積影片熱度（看完/讚會讓影片更熱 → 影響別人的召回/排序）。
 *   3. 回饋迴路：依互動強度把影片標籤加進使用者興趣向量
 *        - completed（看完）權重高、liked（讚）再加碼、skipped（滑掉）幾乎不加。
 *      → 下次取頁時召回更貼、排序分更高（推得更準）。
 */
final class FeedSession
{
    /** 各種互動對「興趣」的回饋強度 */
    private const WEIGHT = [
        'liked' => 1.0,      // 按讚：最強訊號
        'completed' => 0.6,  // 看完：強訊號
        'watched' => 0.3,    // 看了一下
        'skipped' => 0.0,    // 滑掉：負面（這裡記為已看但不加興趣）
    ];

    /** 各種互動對「影片熱度」的加成 */
    private const POP = [
        'liked' => 3,
        'completed' => 2,
        'watched' => 1,
        'skipped' => 0,
    ];

    public function __construct(
        private VideoStore $store,
        private Recommender $recommender,
    ) {
    }

    /** 取一頁 For You（召回→排序→去重→Top-N + 預載下一頁） */
    public function feed(string $userId): array
    {
        return $this->recommender->recommend($userId);
    }

    /**
     * 看一支影片 → 更新興趣訊號（回饋迴路）。
     * @param string $action liked|completed|watched|skipped
     */
    public function watch(string $userId, string $videoId, string $action = 'completed'): array
    {
        $video = $this->store->getVideo($videoId);
        if ($video === null) {
            return ['ok' => false, 'error' => "影片不存在：$videoId"];
        }
        $action = isset(self::WEIGHT[$action]) ? $action : 'watched';

        // 1) 記錄已看（去重）
        $this->store->markWatched($userId, $videoId);

        // 2) 影片熱度 +
        $this->store->bumpPopularity($videoId, self::POP[$action]);

        // 3) 回饋迴路：把影片標籤加進使用者興趣向量
        $weight = self::WEIGHT[$action];
        if ($weight > 0) {
            $this->store->reinforceInterest($userId, $video['tags'], $weight);
        }

        return [
            'ok' => true,
            'user_id' => $userId,
            'video_id' => $videoId,
            'action' => $action,
            'reinforced_tags' => $weight > 0 ? $video['tags'] : [],
            'interest_weight' => $weight,
            'interests_after' => $this->store->getUser($userId)['interests'],
        ];
    }

    /** 看目前狀態（興趣向量 + 已看數） */
    public function state(string $userId): array
    {
        $u = $this->store->getUser($userId);
        return [
            'user_id' => $userId,
            'interests' => $u['interests'],
            'watched' => $u['watched'],
            'watched_count' => count($u['watched']),
        ];
    }

    /** 重新開始一輪（清掉已看記錄；興趣保留） */
    public function resetSession(string $userId): void
    {
        $this->store->resetWatched($userId);
    }
}
