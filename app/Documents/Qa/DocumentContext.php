<?php

namespace App\Documents\Qa;

/**
 * 組合好的文件全文與統計資訊。
 */
final readonly class DocumentContext
{
    public function __construct(
        public string $text,
        public int $pageCount,
        public int $chars,
        public int $estimatedTokens,
    ) {}
}
