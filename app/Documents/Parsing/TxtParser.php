<?php

namespace App\Documents\Parsing;

use App\Documents\Parsing\Exceptions\DocumentParseException;

/**
 * 純文字檔：偵測編碼（UTF-8 / UTF-8 BOM / Big5）並轉為 UTF-8，整份視為第 1 頁。
 */
class TxtParser implements DocumentParserInterface
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    public function parse(string $path): array
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new DocumentParseException('無法讀取檔案。');
        }

        return [new ParsedPage(1, $this->toUtf8($raw))];
    }

    public function hasHardWrappedLines(): bool
    {
        return false;
    }

    public function preservesLayout(): bool
    {
        return false;
    }

    /**
     * 先判斷 UTF-8 再嘗試 Big5：Big5 的位元組組合很寬鬆，許多 UTF-8 中文也「剛好」是合法 Big5，
     * 反過來則很少成立，所以順序不可顛倒。Big5 以 CP950（微軟擴充版）處理，涵蓋常見的擴充字。
     */
    protected function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, self::UTF8_BOM)) {
            $raw = substr($raw, strlen(self::UTF8_BOM));
        }

        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        if (mb_check_encoding($raw, 'CP950')) {
            return mb_convert_encoding($raw, 'UTF-8', 'CP950');
        }

        throw new DocumentParseException('無法辨識文字編碼，目前支援 UTF-8 與 Big5。');
    }
}
