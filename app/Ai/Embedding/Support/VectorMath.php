<?php

namespace App\Ai\Embedding\Support;

use InvalidArgumentException;

/**
 * 向量計算工具，只供實驗使用（llm:embed-probe）。正式搜尋在 Ch07 交給 Qdrant。
 */
final class VectorMath
{
    /**
     * Cosine Similarity：兩個向量夾角的餘弦，1 代表方向完全相同、0 代表無關、-1 代表相反。
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) {
            throw new InvalidArgumentException('Vectors must have the same non-zero dimension.');
        }

        $dot = $normA = $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
