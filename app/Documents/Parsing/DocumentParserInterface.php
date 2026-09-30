<?php

namespace App\Documents\Parsing;

use App\Documents\Parsing\Exceptions\DocumentParseException;

interface DocumentParserInterface
{
    /**
     * 逐頁抽取原始文字（尚未正規化）。空白頁保留為空字串，頁碼不可跳號。
     *
     * @return list<ParsedPage>
     *
     * @throws DocumentParseException 檔案無法解析（壞檔、不支援的編碼等），重試也不會成功
     */
    public function parse(string $path): array;

    /** 抽出的文字是否含排版造成的硬換行（需要 TextNormalizer 接回） */
    public function hasHardWrappedLines(): bool;

    /** 換行、縮排是否有語意（如 Markdown），正規化時不可壓縮 */
    public function preservesLayout(): bool;
}
