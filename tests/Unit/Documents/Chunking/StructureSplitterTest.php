<?php

namespace Tests\Unit\Documents\Chunking;

use App\Documents\Chunking\StructureSplitter;
use App\Documents\Chunking\TextRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StructureSplitterTest extends TestCase
{
    /** @return list<array{string, ?string}> [內容, section] */
    private function split(string $text): array
    {
        return array_map(fn (TextRange $r) => [$r->slice($text), $r->section], (new StructureSplitter)->split($text));
    }

    /** @return array<string, array{string, string}> */
    public static function articleFormats(): array
    {
        return [
            '中文數字' => ['第十二條', '第十二條'],
            '阿拉伯數字加空白' => ['第 12 條', '第 12 條'],
            '阿拉伯數字無空白' => ['第12條', '第12條'],
            '之一（中文）' => ['第十二條之一', '第十二條之一'],
            '之1（阿拉伯）' => ['第12條之1', '第12條之1'],
        ];
    }

    #[DataProvider('articleFormats')]
    public function test_article_heading_formats_are_recognized(string $heading, string $section): void
    {
        $this->assertSame([['前言', null], ["{$heading}\n內容", $section]], $this->split("前言\n{$heading}\n內容"));
    }

    /** @return array<string, array{string}> */
    public static function references(): array
    {
        return [
            '行中引用' => ["第一條\n依第三條規定辦理。"],
            '行首引用（被排版擠到行首）' => ["第一條\n其計算依\n第三條規定辦理。"],
            '之規定' => ["第一條\n第三條之規定不適用之。"],
            '之一後接文字' => ["第一條\n第三條之一規定辦理。"],
        ];
    }

    #[DataProvider('references')]
    public function test_article_references_are_not_boundaries(string $text): void
    {
        $this->assertCount(1, $this->split($text));
    }

    public function test_section_includes_chapter_and_article(): void
    {
        $this->assertSame(
            [["第三條\n事假內容", '第二章 請假 / 第三條'], ["第三條之一\n家庭照顧假內容", '第二章 請假 / 第三條之一']],
            $this->split("第二章　請假\n第三條\n事假內容\n第三條之一\n家庭照顧假內容"),
        );
    }

    public function test_chapter_heading_is_excluded_from_content(): void
    {
        $this->assertSame([["第一條\n內容", '第一章 總則 / 第一條']], $this->split("第一章 總則\n第一條\n內容"));
    }

    public function test_new_chapter_resets_article(): void
    {
        $this->assertSame('第 3 章 附則', $this->split("第一章 總則\n第一條\n內容\n第 3 章 附則\n附則前言")[1][1]);
    }

    public function test_same_line_short_title_is_part_of_section(): void
    {
        $this->assertSame('第五條 請假程序', $this->split("第五條 請假程序\n內容")[0][1]);
    }

    public function test_same_line_sentence_is_not_treated_as_title(): void
    {
        $this->assertSame('第五條', $this->split("第五條 員工應事先以系統提出申請。\n內容")[0][1]);
    }

    public function test_short_adjacent_articles_are_not_merged(): void
    {
        $this->assertCount(3, $this->split("第一條\n甲\n第二條\n乙\n第三條\n丙"));
    }

    public function test_markdown_heading_stack(): void
    {
        $this->assertSame(
            ['手冊', '手冊 / 報到 / 第一天', '手冊 / 設備'],
            array_column($this->split("# 手冊\n說明\n## 報到\n### 第一天\n內容\n## 設備\n內容"), 1),
        );
    }

    public function test_markdown_heading_without_body_is_dropped(): void
    {
        $this->assertNotContains(['## 報到', '手冊 / 報到'], $this->split("# 手冊\n說明\n## 報到\n### 第一天\n內容"));
    }

    public function test_hash_inside_code_fence_is_not_a_heading(): void
    {
        $this->assertCount(1, $this->split("# 範例\n```\n# 這是註解\n```"));
    }

    public function test_text_without_structure_is_a_single_segment(): void
    {
        $this->assertSame([["第一段。\n\n第二段。", null]], $this->split("第一段。\n\n第二段。"));
    }
}
