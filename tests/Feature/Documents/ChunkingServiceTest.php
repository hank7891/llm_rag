<?php

namespace Tests\Feature\Documents;

use App\Documents\Chunking\ChunkDraft;
use App\Documents\Chunking\ChunkingService;
use App\Documents\Parsing\ParserResolver;
use App\Documents\Parsing\TextNormalizer;
use Tests\TestCase;

/**
 * 以 tests/fixtures/documents 的樣本實際解析、正規化後切段（不需要資料庫）。
 */
class ChunkingServiceTest extends TestCase
{
    /** @return list<ChunkDraft> */
    private function chunk(string $fixture, ?string $strategy = null): array
    {
        $path = base_path("tests/fixtures/documents/{$fixture}");
        $parser = $this->app->make(ParserResolver::class)->resolve(mime_content_type($path), pathinfo($path, PATHINFO_EXTENSION));
        $pages = [];

        foreach ((new TextNormalizer)->normalize($parser->parse($path), $parser->hasHardWrappedLines(), $parser->preservesLayout()) as $page) {
            $pages[$page->pageNumber] = $page->content;
        }

        $service = $this->app->make(ChunkingService::class);

        return $service->chunk($pages, $service->defaults()->with(strategy: $strategy));
    }

    private function bySection(string $section): ChunkDraft
    {
        foreach ($this->chunk('regulation.pdf') as $chunk) {
            if ($chunk->section === $section) {
                return $chunk;
            }
        }

        $this->fail("Section [{$section}] not found.");
    }

    public function test_regulation_is_split_one_article_per_chunk(): void
    {
        $this->assertSame(
            ['第一章 總則 / 第一條', '第一章 總則 / 第二條', '第二章 請假 / 第三條', '第二章 請假 / 第三條之一', '第二章 請假 / 第四條', '第二章 請假 / 第四條',
                '第二章 請假 / 第五條', '第 3 章 附則 / 第六條', '第 3 章 附則 / 第7條', '第 3 章 附則 / 第7條之1'],
            array_map(fn (ChunkDraft $c) => $c->section, $this->chunk('regulation.pdf')),
        );
    }

    public function test_short_preamble_is_merged_into_first_section(): void
    {
        // Ch08 實測：只有文件標題的 Chunk 和任何問題都「有點像」，無答案題的 Top-1 都是它
        $first = $this->chunk('regulation.pdf')[0];

        $this->assertSame(['第一章 總則 / 第一條', true], [$first->section, str_starts_with($first->content, '員工差勤管理規章')]);
    }

    public function test_preamble_with_enough_content_stays_separate(): void
    {
        $preamble = str_repeat('本規章說明公司差勤制度的背景與適用原則。', 6);
        $service = $this->app->make(ChunkingService::class);

        $chunks = $service->chunk([1 => "{$preamble}\n第一條 目的\n為建立差勤管理制度，特訂定本規章。"]);

        $this->assertSame([null, '第一條 目的'], array_map(fn (ChunkDraft $c) => $c->section, $chunks));
    }

    public function test_cross_page_article_has_page_range(): void
    {
        $chunk = $this->bySection('第 3 章 附則 / 第六條');

        $this->assertSame([3, 4], [$chunk->pageStart, $chunk->pageEnd]);
    }

    public function test_single_page_article_has_same_start_and_end_page(): void
    {
        $chunk = $this->bySection('第二章 請假 / 第五條');

        $this->assertSame([3, 3], [$chunk->pageStart, $chunk->pageEnd]);
    }

    public function test_article_reference_stays_inside_its_article(): void
    {
        $this->assertStringContainsString('其日數之計算依第三條之一規定辦理', $this->bySection('第二章 請假 / 第五條')->content);
    }

    public function test_long_article_is_split_with_sentence_aligned_overlap(): void
    {
        $parts = array_values(array_filter($this->chunk('regulation.pdf'), fn (ChunkDraft $c) => $c->section === '第二章 請假 / 第四條'));
        $firstSentenceOfSecond = mb_substr($parts[1]->content, 0, mb_strpos($parts[1]->content, '。') + 1);

        $this->assertSame([true, true], [str_starts_with($parts[0]->content, '第四條'), str_contains($parts[0]->content, $firstSentenceOfSecond)]);
    }

    public function test_every_chunk_is_within_max_tokens(): void
    {
        $max = config('rag.chunking.max_tokens');

        foreach (['regulation.pdf', 'it-notice.txt', 'onboarding.md', 'long-handbook.pdf'] as $fixture) {
            foreach ($this->chunk($fixture) as $chunk) {
                $this->assertLessThanOrEqual($max, $chunk->tokenCount, "{$fixture} #{$chunk->index}");
            }
        }
    }

    public function test_content_is_exact_original_text_without_prefix(): void
    {
        $chunk = $this->bySection('第二章 請假 / 第三條');

        $this->assertSame("第三條\n\n事假\n\n員工因私事須親自處理者，得請事假，全年合計不得超過十四日，事假期間不給薪。", $chunk->content);
    }

    public function test_chunk_indexes_are_sequential(): void
    {
        $chunks = $this->chunk('regulation.pdf');

        $this->assertSame(range(0, count($chunks) - 1), array_map(fn (ChunkDraft $c) => $c->index, $chunks));
    }

    public function test_unstructured_text_falls_back_to_paragraph_split(): void
    {
        $chunks = $this->chunk('it-notice.txt');

        $this->assertSame([2, [null, null], ['。', '。']], [count($chunks), array_column($chunks, 'section'), array_map(fn ($c) => mb_substr($c->content, -1), $chunks)]);
    }

    public function test_markdown_sections_follow_headings(): void
    {
        $this->assertSame(
            ['新進員工手冊', '新進員工手冊 / 報到流程 / 第一天', '新進員工手冊 / 報到流程 / 第一週', '新進員工手冊 / 資訊設備', '新進員工手冊 / 常見問題'],
            array_map(fn (ChunkDraft $c) => $c->section, $this->chunk('onboarding.md')),
        );
    }

    public function test_fixed_strategy_ignores_structure(): void
    {
        $chunks = $this->chunk('regulation.pdf', 'fixed');

        $this->assertSame([3, [null, null, null]], [count($chunks), array_column($chunks, 'section')]);
    }
}
