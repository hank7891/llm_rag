<?php

namespace Tests\Unit\Documents\Parsing;

use App\Documents\Parsing\ParsedPage;
use App\Documents\Parsing\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    private TextNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new TextNormalizer;
    }

    /** @param list<string> $contents */
    private function normalize(array $contents, bool $join = false, bool $preserve = false): array
    {
        $pages = array_map(fn ($content, $i) => new ParsedPage($i + 1, $content), $contents, array_keys($contents));

        return array_map(fn (ParsedPage $p) => $p->content, $this->normalizer->normalize($pages, $join, $preserve));
    }

    // ---- 全形半形 ----

    public function test_fullwidth_letters_and_digits_become_halfwidth(): void
    {
        $this->assertSame('ABC abc 0123456789', $this->normalizer->toHalfwidth('ＡＢＣ ａｂｃ ０１２３４５６７８９'));
    }

    public function test_chinese_punctuation_is_preserved(): void
    {
        $text = '請注意：特休，事假；病假！是否？（附件）「引號」『雙引號』、頓號。';

        $this->assertSame($text, $this->normalizer->toHalfwidth($text));
    }

    public function test_fullwidth_hyphen_between_alphanumerics_becomes_halfwidth(): void
    {
        $this->assertSame('代碼 HR-2026', $this->normalizer->toHalfwidth('代碼 ＨＲ－２０２６'));
    }

    public function test_fullwidth_hyphen_elsewhere_is_preserved(): void
    {
        $this->assertSame('說明－補充', $this->normalizer->toHalfwidth('說明－補充'));
    }

    public function test_ideographic_space_is_preserved(): void
    {
        $this->assertSame('第一條　目的', $this->normalizer->toHalfwidth('第一條　目的'));
    }

    // ---- 空白 ----

    public function test_whitespace_is_cleaned_but_leading_indent_kept(): void
    {
        $this->assertSame(["  縮排行 內容\n尾端"], $this->normalize(["  縮排行   內容   \r\n尾端\t "]));
    }

    public function test_multiple_blank_lines_collapse_to_one(): void
    {
        $this->assertSame(["第一段\n\n第二段"], $this->normalize(["\n\n第一段\n\n\n\n第二段\n\n"]));
    }

    public function test_preserve_layout_keeps_markdown_spacing(): void
    {
        $this->assertSame(["# 標題\n\n- 項目  \n    程式碼"], $this->normalize(["# 標題\n\n- 項目  \n    程式碼"], preserve: true));
    }

    // ---- 頁首頁尾 ----

    /** @return list<string> */
    private function pagesWithHeaderAndFooter(): array
    {
        return [
            "公司機密 僅供內部使用\n\n第一條\n目的說明。\n\n第1頁/共3頁",
            "公司機密 僅供內部使用\n\n第二條\n範圍說明。\n\n第2頁/共3頁",
            "公司機密 僅供內部使用\n\n第三條\n附則說明。\n\n第3頁/共3頁",
        ];
    }

    public function test_repeated_header_and_page_number_footer_are_removed(): void
    {
        $this->assertSame(
            ["第一條\n目的說明。", "第二條\n範圍說明。", "第三條\n附則說明。"],
            $this->normalize($this->pagesWithHeaderAndFooter()),
        );
    }

    /** @return array<string, array{string}> */
    public static function pageNumberFormats(): array
    {
        return [
            '第 N 頁' => ['第 %d 頁'],
            '- N -' => ['- %d -'],
            'Page N of M' => ['Page %d of 3'],
            '純數字' => ['%d'],
        ];
    }

    #[DataProvider('pageNumberFormats')]
    public function test_page_number_formats_are_recognized(string $format): void
    {
        $pages = array_map(fn ($i) => "第{$i}段正文。\n".sprintf($format, $i), [1, 2, 3]);

        $this->assertSame(['第1段正文。', '第2段正文。', '第3段正文。'], $this->normalize($pages));
    }

    public function test_article_headings_with_different_numbers_are_not_treated_as_headers(): void
    {
        // 只有頁碼格式的行才遮罩數字；「第1條」「第2條」不可被當成同一行而誤刪
        $pages = ["第1條\n內容一", "第2條\n內容二", "第3條\n內容三"];

        $this->assertSame($pages, $this->normalize($pages));
    }

    public function test_repeated_line_in_the_middle_of_page_is_kept(): void
    {
        $pages = array_map(fn ($i) => "開頭{$i}\n內文{$i}\n公司機密\n內文{$i}b\n結尾{$i}", [1, 2, 3]);

        $this->assertSame($pages, $this->normalize($pages));
    }

    public function test_header_detection_is_skipped_for_fewer_than_three_pages(): void
    {
        $pages = ["公司機密\n第一頁內容", "公司機密\n第二頁內容"];

        $this->assertSame($pages, $this->normalize($pages));
    }

    public function test_blank_page_is_kept_empty(): void
    {
        $pages = [...$this->pagesWithHeaderAndFooter(), ''];

        $this->assertSame('', $this->normalize($pages)[3]);
    }

    // ---- 接回硬換行 ----

    public function test_wrapped_chinese_lines_are_joined_without_space(): void
    {
        $this->assertSame(['特依勞動基準法及相關法令訂定本辦法。'], $this->normalize(["特依勞動\n基準法及相關法令\n訂定本辦法。"], join: true));
    }

    public function test_line_ending_with_sentence_punctuation_is_not_joined(): void
    {
        $this->assertSame(["第一句。\n第二句！\n第三句？\nEnd.\n中文"], $this->normalize(["第一句。\n第二句！\n第三句？\nEnd.\n中文"], join: true));
    }

    public function test_wrapped_english_lines_are_joined_with_space(): void
    {
        $this->assertSame(['applies to all employees and will be reviewed.'], $this->normalize(["applies to all employees and will be\nreviewed."], join: true));
    }

    public function test_english_hyphenation_is_joined_directly(): void
    {
        $this->assertSame(['all full-time staff.'], $this->normalize(["all full-\ntime staff."], join: true));
    }

    public function test_blank_line_between_paragraphs_is_kept(): void
    {
        $this->assertSame(["第一段沒有句號\n\n第二段"], $this->normalize(["第一段沒有句號\n\n第二段"], join: true));
    }

    public function test_article_headings_and_list_items_are_not_joined(): void
    {
        $text = "應依下列規定辦理\n第十二條 特別休假\n1. 第一項\n(二) 第二項\n一、第三項\n- 第四項";

        $this->assertSame([$text], $this->normalize([$text], join: true));
    }

    public function test_lines_are_not_joined_when_disabled(): void
    {
        $this->assertSame(["特依勞動\n基準法"], $this->normalize(["特依勞動\n基準法"]));
    }
}
