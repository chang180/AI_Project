<?php
declare(strict_types=1);

/**
 * Feed 快取（推模型的寫入處）+ 貼文庫
 * --------------------------------------------------
 * 真實系統：
 *   - posts 是貼文庫（單一真相來源），所有貼文內容存這裡。
 *   - feed_cache 是「每使用者預算好的 feed」，通常是 Redis 列表，只存 post_id（截斷最新 N 筆）。
 *     fan-out on write（推模型）就是把 post_id 寫進每個粉絲的這個列表。
 * 這裡用 JSON 檔模擬：posts.json 當貼文庫、feeds.json 當每使用者 feed 快取。
 * feed 只存 post_id，讀時再回貼文庫批次回填內容（hydrate），避免內文複製千萬份。
 */
final class FeedStore
{
    /** 每使用者 feed 快取最多保留的貼文數（超過截斷，更舊的退化成拉） */
    public const FEED_CAP = 50;

    private string $postsFile;
    private string $feedsFile;
    private string $seqFile;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->postsFile = $dataDir . '/posts.json';
        $this->feedsFile = $dataDir . '/feeds.json';
        $this->seqFile = $dataDir . '/post_seq.txt';
        if (!file_exists($this->postsFile)) {
            file_put_contents($this->postsFile, '{}');
        }
        if (!file_exists($this->feedsFile)) {
            file_put_contents($this->feedsFile, '{}');
        }
        if (!file_exists($this->seqFile)) {
            file_put_contents($this->seqFile, '0');
        }
    }

    /** 原子遞增的貼文序號（模擬 DB sequence；也用來當穩定的時間排序鍵） */
    private function nextSeq(): int
    {
        $fp = fopen($this->seqFile, 'c+');
        flock($fp, LOCK_EX);
        $n = (int) trim((string) fread($fp, 64));
        $n++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $n);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $n;
    }

    /** 寫入貼文庫（單一真相來源），回傳貼文紀錄 */
    public function addPost(string $authorId, string $text): array
    {
        $seq = $this->nextSeq();
        $post = [
            'post_id' => 'p_' . $seq,
            'seq' => $seq,                 // 排序鍵（遞增＝時間序）
            'author_id' => $authorId,
            'text' => $text,
            'created_at' => gmdate('c'),
        ];
        $posts = $this->readPosts();
        $posts[$post['post_id']] = $post;
        $this->writePosts($posts);
        return $post;
    }

    /** fan-out on write：把 post_id 推進某粉絲的 feed 快取（截斷保留最新 N 筆） */
    public function pushToFeed(string $userId, string $postId): void
    {
        $feeds = $this->readFeeds();
        $list = (array) ($feeds[$userId] ?? []);
        if (!in_array($postId, $list, true)) {
            $list[] = $postId;
        }
        // 依 seq 排序後截斷，只留最新 FEED_CAP 筆
        $posts = $this->readPosts();
        usort($list, function (string $a, string $b) use ($posts): int {
            return ($posts[$b]['seq'] ?? 0) <=> ($posts[$a]['seq'] ?? 0);
        });
        if (count($list) > self::FEED_CAP) {
            $list = array_slice($list, 0, self::FEED_CAP);
        }
        $feeds[$userId] = array_values($list);
        $this->writeFeeds($feeds);
    }

    /** 取得某使用者「被推進來」的 feed（post_id 列表） */
    public function pushedFeedIds(string $userId): array
    {
        $feeds = $this->readFeeds();
        return array_values((array) ($feeds[$userId] ?? []));
    }

    /** 取某作者最新 N 篇貼文（拉模型即時拉名人貼文用） */
    public function latestPostsByAuthor(string $authorId, int $limit): array
    {
        $out = [];
        foreach ($this->readPosts() as $p) {
            if ($p['author_id'] === $authorId) {
                $out[] = $p;
            }
        }
        usort($out, fn(array $a, array $b): int => $b['seq'] <=> $a['seq']);
        return array_slice($out, 0, $limit);
    }

    /** hydrate：把 post_id 批次回填成完整貼文內容 */
    public function hydrate(array $postIds): array
    {
        $posts = $this->readPosts();
        $out = [];
        foreach ($postIds as $id) {
            if (isset($posts[$id])) {
                $out[] = $posts[$id];
            }
        }
        return $out;
    }

    public function allPosts(): array
    {
        $posts = array_values($this->readPosts());
        usort($posts, fn(array $a, array $b): int => $b['seq'] <=> $a['seq']);
        return $posts;
    }

    private function readPosts(): array
    {
        return json_decode((string) file_get_contents($this->postsFile), true) ?: [];
    }

    private function writePosts(array $posts): void
    {
        file_put_contents(
            $this->postsFile,
            json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function readFeeds(): array
    {
        return json_decode((string) file_get_contents($this->feedsFile), true) ?: [];
    }

    private function writeFeeds(array $feeds): void
    {
        file_put_contents(
            $this->feedsFile,
            json_encode($feeds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
