<?php

namespace Tests\Feature\Rag;

use App\Ai\Chat\Providers\FakeChatProvider;
use App\Models\DocumentChunk;
use App\Rag\Answer\Reference;
use App\Rag\Citation\Citation;
use App\Rag\Citation\CitationFormatter;
use App\Rag\Citation\CitationParser;
use App\Rag\Citation\CitationResolver;
use App\Rag\Citation\CitationResult;
use App\Rag\Citation\InvalidReason;
use App\Rag\Citation\InvalidRef;
use App\Repositories\DocumentRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CitationParser / CitationResolver / CitationFormatter。來源從測試資料庫查詢（MySQL 為資料來源）。
 */
class CitationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<Reference> 編號 1～3 對應三段 Chunk */
    private array $references = [];

    protected function setUp(): void
    {
        parent::setUp();

        $document = (new DocumentRepository)->create(['name' => '員工管理辦法.pdf', 'mime_type' => 'application/pdf', 'path' => 'x', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        $chunks = [['第十二條', 2, 2], ['第十二條', 2, 2], [null, 3, 4]];
        foreach ($chunks as $i => [$section, $start, $end]) {
            $chunk = DocumentChunk::create(['document_id' => $document->id, 'chunk_index' => $i, 'content' => "內容{$i}", 'char_count' => 3, 'token_count' => 3,
                'page_start' => $start, 'page_end' => $end, 'section' => $section, 'chunk_strategy' => 's', 'created_at' => now()]);
            // 編號對照表的檔名刻意與 MySQL 不同：確認顯示的來源取自 MySQL，而不是對照表（Qdrant Payload）
            $this->references[] = new Reference($i + 1, $chunk->id, $document->id, '舊檔名.pdf', $section, $start, $end, 0.7 - $i / 100);
        }
    }

    private function resolve(string $answer, bool $insufficient = false): CitationResult
    {
        return $this->app->make(CitationResolver::class)->resolve($answer, $this->references, $insufficient);
    }

    /** @return list<list<int>|null> */
    private static function parsed(string $text): array
    {
        return array_map(fn ($m) => $m->numbers, (new CitationParser)->parse($text));
    }

    // ---- 解析（3.2 的所有寫法） ----

    /** @return array<string, array{string, list<list<int>|null>}> */
    public static function markers(): array
    {
        return [
            '標準' => ['特休應發給工資 [1]。', [[1]]],
            '連寫' => ['依規定 [1][3]。', [[1], [3]]],
            '逗號' => ['依規定 [1, 3]。', [[1, 3]]],
            '頓號' => ['依規定 [1、3]。', [[1, 3]]],
            '範圍' => ['依規定 [1-3]。', [[1, 2, 3]]],
            '全形' => ['依規定 ［１］。', [[1]]],
            '全形逗號與括號' => ['依規定［１，３］。', [[1, 3]]],
            '非數字' => ['年終獎金 [資料不足]、[來源]。', [null, null]],
            '超過 20 字的方括號不是引用' => ['見 [本規章第三條之一關於家庭照顧假與事假併計之規定說明] 。', []],
        ];
    }

    /** @param list<list<int>|null> $expected */
    #[DataProvider('markers')]
    public function test_marker_formats_are_parsed(string $text, array $expected): void
    {
        $this->assertSame($expected, self::parsed($text));
    }

    // ---- Resolver ----

    public function test_valid_citation_comes_from_mysql_not_reference_table(): void
    {
        $result = $this->resolve('特休應發給工資 [1]。');

        $this->assertSame(
            ['特休應發給工資 [1]。', 1, '員工管理辦法.pdf', '第十二條', 2, 2, 0.7, false],
            [$result->answer, $result->citations[0]->ref, $result->citations[0]->documentName, $result->citations[0]->section, $result->citations[0]->pageStart, $result->citations[0]->pageEnd, $result->citations[0]->score, $result->uncited],
        );
    }

    public function test_out_of_range_reference_is_removed(): void
    {
        $result = $this->resolve('依規定 [1][9]。');

        $this->assertEquals(['依規定 [1]。', [new InvalidRef('[9]', InvalidReason::OutOfRange, 9)]], [$result->answer, $result->invalidRefs]);
    }

    public function test_non_numeric_marker_is_removed(): void
    {
        $result = $this->resolve('特休應發給工資 [1]。年終獎金目前資料中未提及 [資料不足]。');

        $this->assertEquals(
            ['特休應發給工資 [1]。年終獎金目前資料中未提及。', [new InvalidRef('[資料不足]', InvalidReason::NonNumeric)]],
            [$result->answer, $result->invalidRefs],
        );
    }

    public function test_missing_source_is_removed_and_logged(): void
    {
        // 模擬「Qdrant 仍有 Point、MySQL 已刪除」：對照表有編號，但 MySQL 查不到這段 Chunk
        Log::spy();
        DocumentChunk::whereKey($this->references[1]->chunkId)->delete();

        $result = $this->resolve('依規定 [1][2]。');

        $this->assertEquals(['依規定 [1]。', [new InvalidRef('[2]', InvalidReason::SourceMissing, 2)]], [$result->answer, $result->invalidRefs]);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['chunk_id'] === $this->references[1]->chunkId);
    }

    public function test_multi_number_marker_is_normalized_and_partially_kept(): void
    {
        $this->assertSame('依規定 [1][3]，另見 [2]。', $this->resolve('依規定 ［１、３，９］，另見 [2]。')->answer);
    }

    public function test_citations_are_unique_and_sorted_without_renumbering(): void
    {
        $this->assertSame([1, 3], array_map(fn (Citation $c) => $c->ref, $this->resolve('甲 [3]。乙 [1][3]。')->citations));
    }

    public function test_insufficient_answer_has_no_citations_and_markers_are_removed(): void
    {
        $result = $this->resolve('資料不足 [1]。', insufficient: true);

        $this->assertEquals(
            ['資料不足。', [], [new InvalidRef('[1]', InvalidReason::InsufficientAnswer, 1)], false],
            [$result->answer, $result->citations, $result->invalidRefs, $result->uncited],
        );
    }

    public function test_answer_without_valid_citation_is_uncited(): void
    {
        $this->assertTrue($this->resolve('特休應發給工資 [9]。')->uncited);
    }

    public function test_invalid_markers_are_kept_in_answer_when_strip_disabled(): void
    {
        config()->set('rag.citation.strip_invalid', false);

        $result = $this->resolve('依規定 [9]。');

        $this->assertSame(['依規定 [9]。', 1], [$result->answer, count($result->invalidRefs)]);
    }

    public function test_resolver_does_not_call_llm(): void
    {
        $fake = new FakeChatProvider;
        $this->app->instance(FakeChatProvider::class, $fake);

        $this->resolve('依規定 [1]。');

        $this->assertNull($fake->lastMessages);
    }

    // ---- Formatter ----

    private static function citation(int $ref, ?string $section, int $start, int $end, int $documentId = 4): Citation
    {
        return new Citation($ref, $ref, $documentId, '員工管理辦法.pdf', $section, $start, $end, 0.7);
    }

    public function test_single_page_and_page_range_labels(): void
    {
        $formatter = $this->app->make(CitationFormatter::class);

        $this->assertSame(
            ['員工管理辦法.pdf　第十二條　第 2 頁', '員工管理辦法.pdf　第十二條　第 3–4 頁'],
            [$formatter->label(self::citation(1, '第十二條', 2, 2)), $formatter->label(self::citation(2, '第十二條', 3, 4))],
        );
    }

    public function test_empty_section_is_omitted(): void
    {
        $this->assertSame('員工管理辦法.pdf　第 1 頁', $this->app->make(CitationFormatter::class)->label(self::citation(1, null, 1, 1)));
    }

    public function test_same_source_is_merged_into_one_line(): void
    {
        $lines = $this->app->make(CitationFormatter::class)->lines([
            self::citation(1, '第十二條', 2, 2), self::citation(2, '第四條', 2, 2), self::citation(3, '第十二條', 2, 2),
        ]);

        $this->assertSame(['[1][3] 員工管理辦法.pdf　第十二條　第 2 頁', '[2] 員工管理辦法.pdf　第四條　第 2 頁'], $lines);
    }

    public function test_same_name_from_different_documents_is_not_merged(): void
    {
        $lines = $this->app->make(CitationFormatter::class)->lines([self::citation(1, '第十二條', 2, 2), self::citation(2, '第十二條', 2, 2, documentId: 9)]);

        $this->assertCount(2, $lines);
    }

    public function test_no_merge_lists_each_reference(): void
    {
        config()->set('rag.citation.merge_same_source', false);

        $lines = $this->app->make(CitationFormatter::class)->lines([self::citation(1, '第十二條', 2, 2), self::citation(3, '第十二條', 2, 2)]);

        $this->assertSame(['[1] 員工管理辦法.pdf　第十二條　第 2 頁', '[3] 員工管理辦法.pdf　第十二條　第 2 頁'], $lines);
    }
}
