<?php

namespace App\Documents\Chunking;

use Closure;

/**
 * 把超過上限的段落遞迴切小：空行（段落）→ 換行 → 句末標點 → 字元。
 *
 * - 每一層把文字切成單元，貪婪地裝進 Chunk；只有單一單元仍超過上限，才往下一層切。
 * - Overlap 只用在同一段落內被迫切開的位置（換行層以下），段落之間不重疊。
 *   重疊取上一個 Chunk 尾端「連續的完整單元」，且計入 Chunk 的長度上限。
 * - 過短的尾段以原文範圍合併回前一段（不會重複文字），合併後超過上限就保留。
 */
class RecursiveSplitter
{
    private const PARAGRAPH = 0;

    private const LINE = 1;

    private const SENTENCE = 2;

    private const CHARACTER = 3;

    /** 各層的切點：匹配結束的位置就是下一個單元的起點（分隔符留在前一個單元） */
    private const SEPARATORS = [
        self::PARAGRAPH => '/\n[ \t]*\n\s*/u',
        self::LINE => '/\n/u',
        self::SENTENCE => '/[。！？；!?;]+[」』）)"\']*/u',
    ];

    /**
     * @param  Closure(string): int  $estimate  估算 Token 數
     */
    public function __construct(
        private readonly int $maxTokens,
        private readonly int $overlapTokens,
        private readonly int $minTokens,
        private readonly Closure $estimate,
    ) {}

    /** @return list<TextRange> */
    public function split(string $text, TextRange $range): array
    {
        $chunks = $this->splitAt($text, $range, self::PARAGRAPH);

        return $this->mergeShortTail($text, $chunks);
    }

    /**
     * 固定大小切法（實驗對照用）：完全忽略結構與句子，只依長度硬切，使用相同的上限與重疊預算。
     *
     * @return list<TextRange>
     */
    public function splitFixed(string $text, TextRange $range): array
    {
        return $this->mergeShortTail($text, $this->splitByCharacters($text, $range));
    }

    /** @return list<TextRange> */
    private function splitAt(string $text, TextRange $range, int $level): array
    {
        if ($this->tokens($text, $range) <= $this->maxTokens) {
            return [$range];
        }

        if ($level === self::CHARACTER) {
            return $this->splitByCharacters($text, $range);
        }

        $overlap = $level !== self::PARAGRAPH;
        $chunks = [];
        $units = []; // 目前 Chunk 已裝入的單元（用來決定下一個 Chunk 的重疊）

        foreach ($this->units($text, $range, $level) as $unit) {
            // 情況 1：單一單元本身就太長。情況 2：加上這個單元會超過上限，但目前累積的內容太短
            // （例如只有條文標題）——若照常收掉，會產生只有標題的 Chunk（Ch05 實驗發現）
            $tooLong = $this->tokens($text, $unit) > $this->maxTokens;
            $wouldOrphan = $units !== []
                && $this->tokens($text, $this->span($units)) < $this->minTokens
                && $this->tokens($text, $this->span([...$units, $unit])) > $this->maxTokens;

            if ($tooLong || $wouldOrphan) {
                // 單一單元仍太長：連同前面還沒收掉的單元（例如條文標題）一起往下一層切，
                // 否則標題會單獨成為一個 Chunk，和它的正文分開
                $pending = $units === [] ? $unit : $this->span([...$units, $unit]);
                $units = [];
                array_push($chunks, ...$this->splitAt($text, $pending, $level + 1));

                continue;
            }

            if ($units !== [] && $this->tokens($text, $this->span([...$units, $unit])) > $this->maxTokens) {
                $previous = $this->span($units);
                $chunks[] = $previous;
                $tail = $overlap ? $this->overlapTail($text, $previous, $unit) : null;
                $units = $tail === null ? [] : [$tail];
            }

            $units[] = $unit;
        }

        if ($units !== []) {
            $chunks[] = $this->span($units);
        }

        return $chunks;
    }

    /**
     * 下一個 Chunk 開頭要帶入的重疊：上一個 Chunk 尾端「以句子為單位」的連續尾綴，
     * 總長 ≤ overlapTokens，而且加上新單元後不能超過上限。
     * 一律以句子對齊，不受目前所在層級影響（一行可能有好幾句，以行為單位會因一行太長而完全沒有重疊）。
     * 最後一句就超過預算時不重疊，不跳過它去拿更前面的句子。
     */
    private function overlapTail(string $text, TextRange $previous, TextRange $next): ?TextRange
    {
        $tail = null;
        $sentences = $this->units($text, $previous, self::SENTENCE);

        for ($i = count($sentences) - 1; $i >= 0; $i--) {
            $candidate = $previous->withBounds($sentences[$i]->start, $previous->end);

            if ($this->tokens($text, $candidate) > $this->overlapTokens
                || $this->tokens($text, $candidate->withBounds($candidate->start, $next->end)) > $this->maxTokens) {
                break;
            }

            $tail = $candidate;
        }

        // 整個上一段都能當成重疊時不算重疊（下一段必須有新內容以外的上下文意義），只取真正的尾綴
        return $tail !== null && $tail->start > $previous->start ? $tail : null;
    }

    /**
     * 硬切：依字元累積到上限；下一段從上一段尾端往回 overlapTokens 的位置開始，並保證每段都有新內容。
     *
     * @return list<TextRange>
     */
    private function splitByCharacters(string $text, TextRange $range): array
    {
        $chunks = [];
        $start = $range->start;

        while ($start < $range->end) {
            $end = $start + 1;

            while ($end < $range->end && $this->tokens($text, $range->withBounds($start, $end + 1)) <= $this->maxTokens) {
                $end++;
            }

            $chunks[] = $range->withBounds($start, $end);

            if ($end >= $range->end) {
                break;
            }

            $next = $end;
            while ($next - 1 > $start + 1 && $this->tokens($text, $range->withBounds($next - 1, $end)) <= $this->overlapTokens) {
                $next--;
            }
            $start = $next;
        }

        return $chunks;
    }

    /**
     * 依該層的分隔符切成連續、涵蓋整個範圍的單元。
     *
     * @return list<TextRange>
     */
    private function units(string $text, TextRange $range, int $level): array
    {
        $slice = $range->slice($text);
        preg_match_all(self::SEPARATORS[$level], $slice, $matches, PREG_OFFSET_CAPTURE);

        $cuts = [];
        foreach ($matches[0] as [$match, $byteOffset]) {
            // preg 回傳的是 byte 位置，換算成字元位置
            $cuts[] = $range->start + mb_strlen(substr($slice, 0, $byteOffset + strlen($match)));
        }

        $units = [];
        $start = $range->start;
        foreach ([...$cuts, $range->end] as $cut) {
            if ($cut > $start) {
                $units[] = $range->withBounds($start, min($cut, $range->end));
                $start = $cut;
            }
        }

        return $units;
    }

    /**
     * 尾段過短時，以原文範圍（前一段起點 → 尾段終點）合併，重疊部分不會重複；超過上限就不合併。
     *
     * @param  list<TextRange>  $chunks
     * @return list<TextRange>
     */
    private function mergeShortTail(string $text, array $chunks): array
    {
        if (count($chunks) < 2) {
            return $chunks;
        }

        $last = array_pop($chunks);
        $previous = array_pop($chunks);
        $merged = $previous->withBounds($previous->start, $last->end);

        if ($this->tokens($text, $last) < $this->minTokens && $this->tokens($text, $merged) <= $this->maxTokens) {
            return [...$chunks, $merged];
        }

        return [...$chunks, $previous, $last];
    }

    /** @param list<TextRange> $units */
    private function span(array $units): TextRange
    {
        return $units[0]->withBounds($units[0]->start, $units[count($units) - 1]->end);
    }

    private function tokens(string $text, TextRange $range): int
    {
        return ($this->estimate)($range->slice($text));
    }
}
