<?php

namespace App\Ai\Support;

/**
 * 以字數粗估 Token 數（不呼叫模型的 tokenizer）。只用於判斷「大概會不會超過 Context」，不可用於計費。
 *
 * 實測（Ch04，qwen3:8b）：1.67 萬個中文字的規章約 1.24 萬個 Token，每個中文字約 0.74 個 Token。
 * 英文與數字依常見經驗約 4 個字元 1 個 Token。不同模型的 tokenizer 不同，比例只是近似值。
 */
final class TokenEstimator
{
    private const CJK_TOKENS_PER_CHAR = 0.75;

    private const OTHER_TOKENS_PER_CHAR = 0.3;

    public static function estimate(string $text): int
    {
        $cjk = preg_match_all('/[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF00}-\x{FFEF}]/u', $text);
        $other = mb_strlen($text) - $cjk;

        return (int) ceil($cjk * self::CJK_TOKENS_PER_CHAR + $other * self::OTHER_TOKENS_PER_CHAR);
    }
}
