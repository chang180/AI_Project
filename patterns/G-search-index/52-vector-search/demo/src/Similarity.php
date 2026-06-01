<?php
declare(strict_types=1);

/**
 * 相似度度量（向量搜尋的「距離函式」）
 * --------------------------------------------------
 * 向量搜尋的核心是「兩個向量有多像」。常見兩種：
 *   - cosine（餘弦相似度）：看「方向」是否一致，忽略長度。範圍 [-1, 1]，越大越像。
 *   - dot（內積）：方向 + 長度都算。常用於已正規化的嵌入。
 * 若先把向量做 L2 正規化，內積與餘弦相似度等價（這也是業界常見做法）。
 */
final class Similarity
{
    /** @param float[] $a @param float[] $b */
    public static function dot(array $a, array $b): float
    {
        $s = 0.0;
        $n = count($a);
        for ($i = 0; $i < $n; $i++) {
            $s += $a[$i] * $b[$i];
        }
        return $s;
    }

    /** @param float[] $v */
    public static function norm(array $v): float
    {
        return sqrt(self::dot($v, $v));
    }

    /**
     * 餘弦相似度：dot(a,b) / (|a|·|b|)。越大越相似。
     * @param float[] $a @param float[] $b
     */
    public static function cosine(array $a, array $b): float
    {
        $na = self::norm($a);
        $nb = self::norm($b);
        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }
        return self::dot($a, $b) / ($na * $nb);
    }

    /**
     * 依 metric 計算「相似度分數」（越大越相似）。
     * @param float[] $a @param float[] $b
     */
    public static function score(array $a, array $b, string $metric = 'cosine'): float
    {
        return $metric === 'dot' ? self::dot($a, $b) : self::cosine($a, $b);
    }
}
