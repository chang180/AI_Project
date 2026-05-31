<?php
declare(strict_types=1);

/**
 * 社交圖（模擬 follows 表）
 * --------------------------------------------------
 * 真實系統：follows 是雙向索引的分片表
 *   - follower_id → followee_ids（我追蹤誰，拉模型讀此）
 *   - followee_id → follower_ids（我的粉絲是誰，推模型寫此）
 * 並依粉絲數門檻把帳號標記為「名人（celebrity / super-user）」，
 * 名人的貼文改走拉模型，避免一次發文寫爆千萬份 feed。
 *
 * 這裡用 JSON 檔當「DB」，呈現 fan-out 策略判定的核心概念。
 */
final class SocialGraph
{
    private string $usersFile;
    private string $followsFile;

    /** 粉絲數超過此門檻即視為名人 → 其貼文不推、改拉 */
    public const CELEBRITY_THRESHOLD = 3;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->usersFile = $dataDir . '/users.json';
        $this->followsFile = $dataDir . '/follows.json';
        if (!file_exists($this->usersFile)) {
            file_put_contents($this->usersFile, '{}');
        }
        if (!file_exists($this->followsFile)) {
            // 結構：{ "followers": { followee: [follower,...] }, "following": { follower: [followee,...] } }
            file_put_contents($this->followsFile, json_encode([
                'followers' => new stdClass(),
                'following' => new stdClass(),
            ]));
        }
    }

    /** 建立使用者（若不存在） */
    public function ensureUser(string $userId): void
    {
        $users = $this->readUsers();
        if (!isset($users[$userId])) {
            $users[$userId] = ['user_id' => $userId, 'created_at' => gmdate('c')];
            $this->writeUsers($users);
        }
    }

    public function allUsers(): array
    {
        return array_keys($this->readUsers());
    }

    /** follower 追蹤 followee */
    public function follow(string $follower, string $followee): void
    {
        if ($follower === $followee) {
            return;
        }
        $this->ensureUser($follower);
        $this->ensureUser($followee);

        $f = $this->readFollows();
        $followers = (array) ($f['followers'][$followee] ?? []);
        if (!in_array($follower, $followers, true)) {
            $followers[] = $follower;
            $f['followers'][$followee] = $followers;
        }
        $following = (array) ($f['following'][$follower] ?? []);
        if (!in_array($followee, $following, true)) {
            $following[] = $followee;
            $f['following'][$follower] = $following;
        }
        $this->writeFollows($f);
    }

    /** 我追蹤了誰（拉模型讀此） */
    public function followingOf(string $follower): array
    {
        $f = $this->readFollows();
        return array_values((array) ($f['following'][$follower] ?? []));
    }

    /** 某人的粉絲清單（推模型對這些人寫 feed 快取） */
    public function followersOf(string $followee): array
    {
        $f = $this->readFollows();
        return array_values((array) ($f['followers'][$followee] ?? []));
    }

    public function followerCount(string $userId): int
    {
        return count($this->followersOf($userId));
    }

    /** 是否名人：粉絲數超過門檻 → 其貼文改走拉模型 */
    public function isCelebrity(string $userId): bool
    {
        return $this->followerCount($userId) >= self::CELEBRITY_THRESHOLD;
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

    private function readFollows(): array
    {
        $raw = json_decode((string) file_get_contents($this->followsFile), true) ?: [];
        return [
            'followers' => (array) ($raw['followers'] ?? []),
            'following' => (array) ($raw['following'] ?? []),
        ];
    }

    private function writeFollows(array $f): void
    {
        file_put_contents(
            $this->followsFile,
            json_encode($f, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
