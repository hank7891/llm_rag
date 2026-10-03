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
                $this->structure->split($text) ?: [],
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

    private function estimate(string $text): int
    {
        return TokenEstimator::estimate($text, $this->cjkTokensPerChar, $this->otherTokensPerChar);
    }
}
