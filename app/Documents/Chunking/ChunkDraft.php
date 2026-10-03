<?php

namespace App\Documents\Chunking;

/**
 * 切段結果的一段（尚未寫入資料庫）。content 為原文切片，不加任何前綴。
 */
final readonly class ChunkDraft
{
    public function __construct(
        public int $index,
        public string $content,
        public int $start,
        public int $end,
        public int $pageStart,
        public int $pageEnd,
        public ?string $section,
        public int $charCount,
        public int $tokenCount,
    ) {}
}
