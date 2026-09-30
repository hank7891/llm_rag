<?php

namespace App\Documents\Parsing;

use App\Documents\Parsing\Exceptions\DocumentParseException;

/**
 * 依實際 MIME（finfo 偵測）與副檔名選擇 Parser。
 * Markdown 的 MIME 通常也是 text/plain，所以需要搭配副檔名區分。
 */
class ParserResolver
{
    public function __construct(
        private readonly PdfParser $pdf,
        private readonly TxtParser $txt,
        private readonly MarkdownParser $markdown,
    ) {}

    public function resolve(string $mimeType, string $extension): DocumentParserInterface
    {
        return match (true) {
            $mimeType === 'application/pdf' => $this->pdf,
            in_array($mimeType, ['text/plain', 'text/markdown'], true) && in_array(strtolower($extension), ['md', 'markdown'], true) => $this->markdown,
            $mimeType === 'text/plain' => $this->txt,
            default => throw new DocumentParseException("不支援的檔案類型（{$mimeType}）。"),
        };
    }
}
