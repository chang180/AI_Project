<?php
declare(strict_types=1);

/**
 * 影片庫 + 使用者狀態（興趣訊號、觀看記錄）
 * --------------------------------------------------
 * 真實系統：
 *   - videos 是影片中繼資料庫（分片）：id、標籤、熱度、上架時間；
 *     影片本體經轉碼後放物件儲存 / CDN（這裡不存真影片，只存中繼資料）。
 *   - 使用者興趣向量（哪個標籤偏好多少分）通常放鍵值儲存 / 特徵庫，
 *     由觀看/讚等回饋事件即時或批次更新。
 *   - 觀看記錄（已看過哪些）用來「同一輪 session 不重複」推薦。
 * 這裡用 JSON 檔模擬，呈現「召回 → 排序 → 去重 → 興趣回饋」的核心邏輯。
 */
final class VideoStore
{
    private string $videosFile;
    private string $usersFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->videosFile = $dataDir . '/videos.json';
        $this->usersFile = $dataDir . '/users.json';
        if (!file_exists($this->videosFile)) {
            file_put_contents($this->videosFile, '{}');
        }
        if (!file_exists($this->usersFile)) {
            file_put_contents($this->usersFile, '{}');
        }
    }

    // ---------------- 影片庫 ----------------

    /**
     * 新增（或覆寫）一支影片中繼資料。
     * $ageHours：上架至今幾小時（越小越新鮮）；$popularity：累積熱度（讚/看次）。
     */
    public function addVideo(string $id, array $tags, int $popularity = 0, int $ageHours = 0): array
    {
        $videos = $this->readVideos();
        $videos[$id] = [
            'id' => $id,
            'tags' => array_values($tags),
            'popularity' => $popularity,
            'age_hours' => $ageHours,
        ];
        $this->writeVideos($videos);
        return $videos[$id];
    }

    public function getVideo(string $id): ?array
    {
        $videos = $this->readVideos();
        return $videos[$id] ?? null;
    }

    /** @return array<int,array> 全部影片 */
    public function allVideos(): array
    {
        return array_values($this->readVideos());
    }

    /** 累積熱度（看完 / 讚都會讓影片更熱，影響別人的召回與排序） */
    public function bumpPopularity(string $id, int $delta): void
    {
        $videos = $this->readVideos();
        if (isset($videos[$id])) {
            $videos[$id]['popularity'] += $delta;
            $this->writeVideos($videos);
        }
    }

    // ---------------- 使用者狀態 ----------------

    private function blankUser(string $userId): array
    {
        return [
            'user_id' => $userId,
            'interests' => new stdClass(),  // tag => 分數（興趣向量）
            'watched' => [],                // 本輪 session 已看過的 video_id（去重用）
        ];
    }

    public function getUser(string $userId): array
    {
        $users = $this->readUsers();
        $u = $users[$userId] ?? null;
        if ($u === null) {
            return [
                'user_id' => $userId,
                'interests' => [],
                'watched' => [],
            ];
        }
        return [
            'user_id' => $userId,
            'interests' => (array) ($u['interests'] ?? []),
            'watched' => array_values((array) ($u['watched'] ?? [])),
        ];
    }

    /** 設定初始興趣（示範 seed 用） */
    public function setInterests(string $userId, array $interests): void
    {
        $users = $this->readUsers();
        $u = $users[$userId] ?? $this->blankUser($userId);
        $u['interests'] = $interests;
        $users[$userId] = $u;
        $this->writeUsers($users);
    }

    /**
     * 回饋迴路：看一支影片後，把它的標籤加進使用者興趣向量。
     * 看完（watched）加較多、只是滑過（skip）幾乎不加；按讚再加碼。
     * → 下次召回/排序就更貼這個人的興趣（推得更準）。
     */
    public function reinforceInterest(string $userId, array $tags, float $weight): void
    {
        $users = $this->readUsers();
        $u = $users[$userId] ?? $this->blankUser($userId);
        $interests = (array) ($u['interests'] ?? []);
        foreach ($tags as $tag) {
            $interests[$tag] = round((float) ($interests[$tag] ?? 0) + $weight, 3);
        }
        $u['interests'] = $interests;
        $users[$userId] = $u;
        $this->writeUsers($users);
    }

    /** 記錄「本輪已看」→ 同一輪取下一頁時不再重複推 */
    public function markWatched(string $userId, string $videoId): void
    {
        $users = $this->readUsers();
        $u = $users[$userId] ?? $this->blankUser($userId);
        $watched = (array) ($u['watched'] ?? []);
        if (!in_array($videoId, $watched, true)) {
            $watched[] = $videoId;
        }
        $u['watched'] = array_values($watched);
        $users[$userId] = $u;
        $this->writeUsers($users);
    }

    public function watchedSet(string $userId): array
    {
        return $this->getUser($userId)['watched'];
    }

    /** 清掉本輪已看記錄（重新開始一輪 For You） */
    public function resetWatched(string $userId): void
    {
        $users = $this->readUsers();
        if (isset($users[$userId])) {
            $users[$userId]['watched'] = [];
            $this->writeUsers($users);
        }
    }

    // ---------------- 持久化 ----------------

    private function readVideos(): array
    {
        return json_decode((string) file_get_contents($this->videosFile), true) ?: [];
    }

    private function writeVideos(array $videos): void
    {
        file_put_contents(
            $this->videosFile,
            json_encode($videos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function readUsers(): array
    {
        return json_decode((string) file_get_contents($this->usersFile), true) ?: [];
    }

    private function writeUsers(array $users): void
    {
        file_put_contents(
            $this->usersFile,
            json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
