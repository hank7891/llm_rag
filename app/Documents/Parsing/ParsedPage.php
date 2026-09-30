<?php

namespace App\Documents\Parsing;

/**
 * 解析出的一頁文字。pageNumber 從 1 開始，對應原檔的實體頁序（非印刷頁碼）。
 */
final readonly class ParsedPage
{
    public function __construct(
        public int $pageNumber,
        public string $content,
    ) {}
}
