<?php

namespace App\Documents;

/**
 * 文件結構標記的判斷規則，TextNormalizer（接回硬換行）與 StructureSplitter（切段）共用，避免兩邊判斷不一致。
 *
 * 條文標題必須是「第 X 條」或「第 X 條之 Y」，後面只能接空白或換行（可再接標題文字）。
 * 後面直接接其他文字的是內文引用，例如「第三條之一規定辦理」「第十二條之規定」，不是標題。
 */
final class StructureMarkers
{
    private const NUMBER = '[一二三四五六七八九十百千零〇\d]+';

    /** 第一條、第 12 條、第十二條之一、第7條之1（後面須為空白或行尾） */
    public const ARTICLE = '/^\s*(第\s*'.self::NUMBER.'\s*條(?:\s*之\s*'.self::NUMBER.')?)(?=\s|$)/u';

    /** 第一章、第 3 章（後面須為空白或行尾） */
    public const CHAPTER = '/^\s*(第\s*'.self::NUMBER.'\s*章)(?=\s|$)/u';

    /** Markdown 標題 #～### */
    public const MARKDOWN_HEADING = '/^(#{1,3})\s+(\S.*)$/u';

    /** 清單項目：1.、一、、(一)、（1）、- 項目 */
    private const LIST_ITEM = '/^\s*(\d+[.、)]|[一二三四五六七八九十]+、|[（(][一二三四五六七八九十\d]+[）)]|[-*•・]\s)/u';

    public static function isArticle(string $line): bool
    {
        return preg_match(self::ARTICLE, $line) === 1;
    }

    public static function isChapter(string $line): bool
    {
        return preg_match(self::CHAPTER, $line) === 1;
    }

    /** 條、章或 Markdown 標題 */
    public static function isHeading(string $line): bool
    {
        return self::isArticle($line) || self::isChapter($line) || preg_match(self::MARKDOWN_HEADING, $line) === 1;
    }

    public static function isListItem(string $line): bool
    {
        return preg_match(self::LIST_ITEM, $line) === 1;
    }
}
