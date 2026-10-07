<?php

namespace App\Rag\Search;

use App\Documents\Parsing\TextNormalizer;

/**
 * 關鍵字搜尋的正規化。建立索引（document_chunks.search_text）與查詢前都必須呼叫同一個函式，
 * 否則 ngram 切出來的詞對不上，結果會「靜靜地變差」而不會報錯。
 *
 * 1. 全形英數轉半形（沿用 Ch03 TextNormalizer）
 * 2. 「第十二條」「第三條之一」的中文數字轉成阿拉伯數字：使用者常打「第12條」，ngram 的「12」對不上「十二」
 * 3. 移除中文字與中文標點旁邊的空白：「第 12 條」→「第12條」（ngram 會略過含空白的詞元）
 * 4. 英文轉小寫
 * 5. 移除英數字之間的連字號與底線：「HRIS-3」→「hris3」。ngram_token_size = 2 時，連字號切出的 1 字片段（「3」）
 *    不會進入索引，片語查詢「hris-3」整個查不到（Ch11 實測）；「hr-2026」這種每段都 ≥ 2 字的編號不受影響，但一併統一
 */
class SearchTextNormalizer
{
    private const DIGITS = ['零' => 0, '〇' => 0, '一' => 1, '二' => 2, '兩' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];

    private const UNITS = ['十' => 10, '百' => 100, '千' => 1000];

    private const NUMERAL = '[零〇一二兩三四五六七八九十百千]+';

    public function __construct(private readonly TextNormalizer $text = new TextNormalizer) {}

    public function normalize(string $text): string
    {
        $text = $this->text->toHalfwidth($text);

        $text = preg_replace_callback('/第\s*('.self::NUMERAL.')\s*(條|章|節|款|項)(?:\s*之\s*('.self::NUMERAL.'))?/u', fn (array $m) => '第'.$this->toNumber($m[1]).$m[2].(isset($m[3]) && $m[3] !== '' ? '之'.$this->toNumber($m[3]) : ''), $text);
        // 阿拉伯數字的「之一」也統一：「第7條之一」→「第7條之1」
        $text = preg_replace_callback('/(條|章)\s*之\s*('.self::NUMERAL.')/u', fn (array $m) => $m[1].'之'.$this->toNumber($m[2]), $text);

        $cjk = '[\p{Han}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]'; // 中文字、中文標點、全形符號
        $text = preg_replace("/\\s+(?={$cjk})|(?<={$cjk})\\s+/u", '', $text);

        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));

        return preg_replace('/(?<=[a-z0-9])[-_](?=[a-z0-9])/', '', $text);
    }

    /** 十二 → 12、二十五 → 25、一百零三 → 103、十 → 10 */
    private function toNumber(string $numeral): int
    {
        $total = 0;
        $current = 0;

        foreach (mb_str_split($numeral) as $char) {
            if (isset(self::DIGITS[$char])) {
                $current = self::DIGITS[$char];

                continue;
            }

            $total += ($current === 0 ? 1 : $current) * self::UNITS[$char];
            $current = 0;
        }

        return $total + $current;
    }
}
