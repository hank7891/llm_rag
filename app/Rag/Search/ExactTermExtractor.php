<?php

namespace App\Rag\Search;

/**
 * 以規則從（已正規化的）問題中抽出「精確詞」：英數編號、條號、引號內字串。
 * 精確詞以 BOOLEAN MODE 片語查詢命中的 Chunk，可以讓問題通過「資料不足」的判斷；一般關鍵字不行。
 */
class ExactTermExtractor
{
    private const PATTERNS = [
        // 條號：第12條、第3條之1、第3章（SearchTextNormalizer 已把中文數字轉成阿拉伯數字）
        '/第\d+(?:條|章)(?:之\d+)?/u',
        // 英文字母與數字混合的編號。SearchTextNormalizer 已移除連字號：HR-2026 → hr2026、BUG-2026-000183 → bug2026000183
        '/(?<![a-z0-9])(?=[a-z0-9]*[a-z])(?=[a-z0-9]*\d)[a-z0-9]{3,}(?![a-z0-9])/',
    ];

    /** 引號內的字串：「年度特別休假」、"Kick-off" */
    private const QUOTED = '/[「『"“]([^」』"”]{2,30})[」』"”]/u';

    /** @return list<string> 不重複；短於 2 字的詞以 ngram（2 字）切不出詞元，不採用 */
    public function extract(string $normalizedQuery): array
    {
        $terms = [];

        foreach (self::PATTERNS as $pattern) {
            preg_match_all($pattern, $normalizedQuery, $m);
            array_push($terms, ...$m[0]);
        }

        preg_match_all(self::QUOTED, $normalizedQuery, $m);
        array_push($terms, ...$m[1]);

        return array_values(array_unique(array_filter($terms, fn (string $t) => mb_strlen($t) >= 2)));
    }
}
