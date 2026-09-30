<?php

namespace Tests\Unit\Documents\Parsing;

use App\Documents\Parsing\Exceptions\DocumentParseException;
use App\Documents\Parsing\MarkdownParser;
use App\Documents\Parsing\ParsedPage;
use App\Documents\Parsing\ParserResolver;
use App\Documents\Parsing\PdfParser;
use App\Documents\Parsing\TxtParser;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 以 tests/fixtures/documents 的樣本檔實際解析（PDF 會呼叫本機的 pdftotext）。
 * 樣本來源與產生方式見 tests/fixtures/documents/README.md。
 */
class ParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return base_path("tests/fixtures/documents/{$name}");
    }

    /** @param list<ParsedPage> $pages */
    private function contents(array $pages): array
    {
        return array_map(fn (ParsedPage $p) => $p->content, $pages);
    }

    // ---- TXT ----

    /** @return array<string, array{string}> */
    public static function textEncodings(): array
    {
        return ['UTF-8' => ['notice-utf8.txt'], 'UTF-8 BOM' => ['notice-utf8-bom.txt'], 'Big5' => ['notice-big5.txt']];
    }

    #[DataProvider('textEncodings')]
    public function test_txt_in_any_supported_encoding_becomes_identical_utf8(string $file): void
    {
        $this->assertSame(
            $this->contents((new TxtParser)->parse($this->fixture('notice-utf8.txt'))),
            $this->contents((new TxtParser)->parse($this->fixture($file))),
        );
    }

    public function test_txt_is_a_single_page_without_bom(): void
    {
        $pages = (new TxtParser)->parse($this->fixture('notice-utf8-bom.txt'));

        $this->assertSame([1, '請假規則'], [$pages[0]->pageNumber, strtok($pages[0]->content, "\n")]);
    }

    public function test_unknown_encoding_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'enc');
        file_put_contents($path, "\xFF\xFE\x00\xD8\x81");

        $this->expectException(DocumentParseException::class);
        $this->expectExceptionMessage('無法辨識文字編碼');

        (new TxtParser)->parse($path);
    }

    // ---- Markdown ----

    public function test_markdown_keeps_heading_marks(): void
    {
        $content = (new MarkdownParser)->parse($this->fixture('guide.md'))[0]->content;

        $this->assertStringStartsWith("# 新進員工指南\n\n## 報到流程", $content);
    }

    public function test_markdown_preserves_layout(): void
    {
        $this->assertTrue((new MarkdownParser)->preservesLayout());
    }

    // ---- PDF ----

    private function pdfParser(): PdfParser
    {
        return new PdfParser(config('documents.pdftotext.binary'), 30);
    }

    public function test_pdf_page_count_matches_original(): void
    {
        $this->assertSame([1, 2, 3], array_map(fn (ParsedPage $p) => $p->pageNumber, $this->pdfParser()->parse($this->fixture('handbook.pdf'))));
    }

    public function test_pdf_chinese_text_is_extracted_per_page(): void
    {
        $pages = $this->pdfParser()->parse($this->fixture('handbook.pdf'));

        $this->assertSame(
            [true, true, true],
            [str_contains($pages[0]->content, '第一條'), str_contains($pages[1]->content, '特別休'), str_contains($pages[2]->content, '董事會核定')],
        );
    }

    public function test_pdf_blank_page_in_the_middle_is_kept(): void
    {
        Process::fake(['*' => Process::result(output: "第一頁\f\f第三頁\f")]);

        $this->assertSame(['第一頁', '', '第三頁'], $this->contents($this->pdfParser()->parse('any.pdf')));
    }

    public function test_broken_pdf_is_rejected_with_readable_message(): void
    {
        $this->expectException(DocumentParseException::class);
        $this->expectExceptionMessage('PDF 解析失敗');

        $this->pdfParser()->parse($this->fixture('broken.pdf'));
    }

    public function test_scanned_pdf_has_no_text_layer(): void
    {
        $this->assertSame([''], array_map('trim', $this->contents($this->pdfParser()->parse($this->fixture('scanned.pdf')))));
    }

    // ---- 選擇 Parser ----

    /** @return array<string, array{string, string, class-string}> */
    public static function parserChoices(): array
    {
        return [
            'pdf' => ['application/pdf', 'pdf', PdfParser::class],
            'txt' => ['text/plain', 'txt', TxtParser::class],
            'markdown（MIME 為 text/plain）' => ['text/plain', 'md', MarkdownParser::class],
            'markdown（MIME 為 text/markdown）' => ['text/markdown', 'MD', MarkdownParser::class],
        ];
    }

    #[DataProvider('parserChoices')]
    public function test_resolver_picks_parser_by_mime_and_extension(string $mime, string $extension, string $expected): void
    {
        $this->assertInstanceOf($expected, $this->app->make(ParserResolver::class)->resolve($mime, $extension));
    }

    public function test_resolver_rejects_unsupported_type(): void
    {
        $this->expectException(DocumentParseException::class);
        $this->expectExceptionMessage('不支援的檔案類型（image/png）');

        $this->app->make(ParserResolver::class)->resolve('image/png', 'png');
    }
}
