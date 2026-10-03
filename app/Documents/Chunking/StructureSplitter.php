<?php

namespace App\Documents\Chunking;

use App\Documents\StructureMarkers;

/**
 * 依文件結構（章、條、Markdown 標題）把全文切成結構段落，並記錄每段的 section。
 *
 * - 章標題行不放進任何段落，只更新 section（例如「第二章 請假」）。
 * - 條標題行開始一個新段落（標題保留在內容中）。條名只在同一行明確寫出時採用，
 *   否則只記條號：下一行的短句無法可靠區分是條名還是正文。
 * - Markdown #～### 開始新段落；遇到較高層級的標題時清掉較低層級。程式碼區塊內的 # 不是標題。
 * - 第一個標題前的文字自成一段。一條一段，相鄰的條即使很短也不合併。
 * - 沒有任何結構的文件，整份成為一個段落，交給 RecursiveSplitter 依段落與句子切分。
 */
class StructureSplitter
{
    /** 同一行的條名最多幾個字；更長或含句末標點的視為正文 */
    private const MAX_TITLE_CHARS = 20;

    /** @return list<TextRange> */
    public function split(string $text): array
    {
        $segments = [];
        $segmentStart = null;
        $chapter = null;
        $article = null;
        $headings = [];
        $inCodeFence = false;
        $offset = 0;

        $close = function (int $end) use (&$segments, &$segmentStart, &$chapter, &$article, &$headings) {
            if ($segmentStart !== null && $end > $segmentStart) {
                $segments[] = new TextRange($segmentStart, $end, $this->section($chapter, $article, $headings));
            }
            $segmentStart = null;
        };

        foreach (explode("\n", $text) as $line) {
            $lineStart = $offset;
            $offset += mb_strlen($line) + 1; // +1：換行字元

            if (str_starts_with(ltrim($line), '```')) {
                $inCodeFence = ! $inCodeFence;
            }

            if (! $inCodeFence && StructureMarkers::isChapter($line)) {
                $close($lineStart);
                $chapter = $this->collapseSpaces($line);
                $article = null;

                continue; // 章標題只作為 section，不放進內容
            }

            if (! $inCodeFence && preg_match(StructureMarkers::ARTICLE, $line, $m) === 1) {
                $close($lineStart);
                $article = $this->articleLabel($m[1], mb_substr(trim($line), mb_strlen(trim($m[1]))));
                $segmentStart = $lineStart;

                continue;
            }

            if (! $inCodeFence && preg_match(StructureMarkers::MARKDOWN_HEADING, $line, $m) === 1) {
                $close($lineStart);
                $level = strlen($m[1]);
                $headings = array_slice($headings, 0, $level - 1, true) + [$level - 1 => trim($m[2])];
                $segmentStart = $lineStart;

                continue;
            }

            $segmentStart ??= $lineStart;
        }

        $close(mb_strlen($text));

        return array_values(array_filter(
            array_map(fn (TextRange $r) => $r->trimmed($text), $segments),
            // 只有 Markdown 標題、沒有內文的段落（例如 ## 後面直接接 ###）不需要獨立成段：標題已記在下層段落的 section
            fn (?TextRange $r) => $r !== null && ! $this->isHeadingOnly($r->slice($text)),
        ));
    }

    private function isHeadingOnly(string $segment): bool
    {
        return ! str_contains($segment, "\n") && preg_match(StructureMarkers::MARKDOWN_HEADING, $segment) === 1;
    }

    /** 「第三條之一」加上同一行明確的條名（≤20 字、不含句末標點）；否則只用條號 */
    private function articleLabel(string $number, string $rest): string
    {
        $number = $this->collapseSpaces($number);
        $title = trim($rest);

        $isTitle = $title !== ''
            && mb_strlen($title) <= self::MAX_TITLE_CHARS
            && preg_match('/[。！？；，,.!?;]/u', $title) !== 1;

        return $isTitle ? "{$number} {$title}" : $number;
    }

    /** @param array<int, string> $headings */
    private function section(?string $chapter, ?string $article, array $headings): ?string
    {
        $parts = array_values(array_filter([$chapter, $article, ...$headings], fn ($p) => $p !== null && $p !== ''));

        return $parts === [] ? null : implode(' / ', $parts);
    }

    /** 把全形空白與連續空白壓成一個半形空白：「第一章　總則」→「第一章 總則」 */
    private function collapseSpaces(string $text): string
    {
        return trim(preg_replace('/[\s\x{3000}]+/u', ' ', $text));
    }
}
