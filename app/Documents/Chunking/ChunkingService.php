<?php

namespace App\Documents\Chunking;

use App\Ai\Support\TokenEstimator;
use InvalidArgumentException;

/**
 * 切段流程：串成全文（Offset Map）→ 結構段落 → 過長的再遞迴切小 → 以起訖位置反查頁碼。
 * 沒有任何結構的文件會自然退化成段落與句子切分，不另寫一套邏輯。
 */
class ChunkingService
{
    public function __construct(
        private readonly PageTextAssembler $assembler,
        private readonly StructureSplitter $structure,
        private readonly ChunkingOptions $defaults,
        private readonly float $cjkTokensPerChar,
        private readonly float $otherTokensPerChar,
    ) {}

    public function defaults(): ChunkingOptions
    {
        return $this->defaults;
    }

    /**
     * @param  array<int, string>  $pages  頁碼 → 內容
     * @return list<ChunkDraft>
     */
    public function chunk(array $pages, ?ChunkingOptions $options = null): array
    {
        $options ??= $this->defaults;
        $assembled = $this->assembler->assemble($pages);
        $text = $assembled->text;
        $splitter = new RecursiveSplitter($options->maxTokens, $options->overlapTokens(), $options->minTokens, $this->estimate(...));

        $ranges = match ($options->strategy) {
            ChunkingOptions::STRUCTURE => array_merge(...array_map(
                fn (TextRange $segment) => $splitter->split($text, $segment),
                $this->mergeShortPreamble($text, $this->structure->split($text), $options->minTokens),
            )),
            ChunkingOptions::FIXED => $splitter->splitFixed($text, new TextRange(0, mb_strlen($text))),
            default => throw new InvalidArgumentException("Unknown chunking strategy [{$options->strategy}]."),
        };

        $drafts = [];

        foreach ($ranges as $range) {
            $range = $range->trimmed($text);

            if ($range === null) {
                continue;
            }

            $content = $range->slice($text);
            [$pageStart, $pageEnd] = $assembled->pageRange($range->start, $range->end);

            $drafts[] = new ChunkDraft(
                index: count($drafts),
                content: $content,
                start: $range->start,
                end: $range->end,
                pageStart: $pageStart,
                pageEnd: $pageEnd,
                section: $range->section,
                charCount: mb_strlen($content),
                tokenCount: $this->estimate($content),
            );
        }

        return $drafts;
    }

    /**
     * 第一個標題前的文字（通常只有文件標題）太短時併入下一段，不單獨成為 Chunk。
     * Ch08 實測：只有標題的 Chunk 和任何問題都「有點像」，無答案題的 Top-1 都是它，門檻無法區分兩群。
     *
     * @param  list<TextRange>  $segments
     * @return list<TextRange>
     */
    private function mergeShortPreamble(string $text, array $segments, int $minTokens): array
    {
        if (count($segments) < 2 || $segments[0]->section !== null || $this->estimate($segments[0]->slice($text)) >= $minTokens) {
            return $segments;
        }

        // 範圍連續到下一段結尾，中間的章標題行也一起保留在內容中（內容仍是原文切片）
        return [$segments[1]->withBounds($segments[0]->start, $segments[1]->end), ...array_slice($segments, 2)];
    }

    private function estimate(string $text): int
    {
        return TokenEstimator::estimate($text, $this->cjkTokensPerChar, $this->otherTokensPerChar);
    }
}
