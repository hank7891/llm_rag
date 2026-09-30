<?php

namespace App\Documents\Parsing;

/**
 * Markdown：編碼處理同純文字，整份視為第 1 頁，保留 # 標題等標記供 Ch04 依標題切分。
 */
class MarkdownParser extends TxtParser
{
    public function preservesLayout(): bool
    {
        return true;
    }
}
