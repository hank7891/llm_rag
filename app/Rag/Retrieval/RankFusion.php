<?php

namespace App\Rag\Retrieval;

/**
 * Reciprocal Rank Fusion：只看名次、不看分數。
 * Cosine 0.78 與 FULLTEXT 12.4 尺度不同，相加或加權都沒有意義；各清單的名次換算成 1 / (k + 名次) 再加總，
 * 只出現在一份清單的結果，另一份不計分。
 */
class RankFusion
{
    /**
     * @param  array<string, list<int>>  $lists  清單名稱 → 依名次排序的 id
     * @return array<int, float> id → RRF 分數，由高到低（同分時保留先出現的順序）
     */
    public function fuse(array $lists, int $k): array
    {
        $scores = [];

        foreach ($lists as $ids) {
            foreach (array_values($ids) as $index => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + 1 / ($k + $index + 1);
            }
        }

        // 穩定排序：同分時維持第一次出現的順序
        $order = array_flip(array_keys($scores));
        uksort($scores, fn (int $a, int $b) => [$scores[$b], $order[$a]] <=> [$scores[$a], $order[$b]]);

        return $scores;
    }
}
