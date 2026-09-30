<?php

namespace App\Documents\Parsing;

use App\Documents\Parsing\Exceptions\DocumentParseException;
use Illuminate\Support\Facades\Process;

/**
 * 文字型 PDF：呼叫 poppler 的 pdftotext，依換頁字元 \f 分頁。
 * 不用 PHP 的 PDF 套件：中文 PDF 常使用嵌入的 CID 字型，PHP 套件容易抽出亂碼。
 * 使用預設的閱讀順序模式，不用 -layout：-layout 會為了對齊版面補上大量空白。
 */
class PdfParser implements DocumentParserInterface
{
    public function __construct(
        private readonly string $binary,
        private readonly int $timeout,
    ) {}

    public function parse(string $path): array
    {
        $result = Process::timeout($this->timeout)->run([$this->binary, '-enc', 'UTF-8', $path, '-']);

        if (! $result->successful()) {
            // pdftotext 的錯誤常有多行，第一行最能說明原因（如 May not be a PDF file）
            $detail = strtok(trim($result->errorOutput()), "\n") ?: "exit code {$result->exitCode()}";

            throw new DocumentParseException("PDF 解析失敗，檔案可能已損毀或受密碼保護。（{$detail}）");
        }

        // pdftotext 在每一頁結尾輸出一個 \f，切開後最後一段是分隔符產生的空元素，只移除這一段；
        // 中間的空白頁要保留為空字串，否則後面的頁碼會全部錯位
        $segments = explode("\f", $result->output());
        array_pop($segments);

        if ($segments === []) {
            throw new DocumentParseException('PDF 沒有任何頁面。');
        }

        return array_map(
            fn (int $index, string $content) => new ParsedPage($index + 1, $content),
            array_keys($segments),
            $segments,
        );
    }

    public function hasHardWrappedLines(): bool
    {
        return true;
    }

    public function preservesLayout(): bool
    {
        return false;
    }
}
