<?php

namespace Tests\Feature\Rag;

use App\Documents\Chunking\ChunkDraft;
use App\Documents\DocumentStatus;
use App\Models\Document;
use App\Rag\Search\ExactTermExtractor;
use App\Rag\Search\SearchTextNormalizer;
use App\Repositories\DocumentChunkRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\KeywordSearchRepository;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * KeywordSearchRepository 的真實 SQL（MySQL FULLTEXT ngram）。
 *
 * InnoDB FULLTEXT 看不到尚未提交的資料，所以這組測試不使用 DatabaseTransactions：
 * 在測試資料庫（rag_poc_testing）提交資料，結束時刪除。預設不執行：php artisan test --group=fulltext
 */
#[Group('fulltext')]
class KeywordSearchTest extends TestCase
{
    private const PREFIX = 'fulltext-test-';

    /** @var array<string, int> 名稱 → chunk id */
    private array $chunks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanUp();
        $this->seedDocument('規章', DocumentStatus::Indexed, [
            "第十二條\n特別休假\n申請系統代碼為HR-2026，相關表單請至內部網站下載。",
            "第7條之1\n過渡條款\n本規章施行前已核准之請假，依原規定辦理。",
            '表單編號 BUG-2026-000183 為系統異常回報單，2026 年度另有其他表單。',
            '人事系統 HRIS-3 發生錯誤時使用此表單。',
        ]);
        $this->seedDocument('處理中', DocumentStatus::Indexing, ["第十二條\n特別休假\n處理中的文件不應被搜尋到。"]);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        // document_chunks 隨文件 cascade 刪除
        Document::where('name', 'like', self::PREFIX.'%')->delete();
    }

    /** @param list<string> $contents */
    private function seedDocument(string $name, DocumentStatus $status, array $contents): void
    {
        $document = (new DocumentRepository)->create(['name' => self::PREFIX.$name, 'mime_type' => 'text/plain', 'path' => 'x', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        $document->forceFill(['status_key' => $status])->save();

        // 走正式的寫入路徑：replace() 會同時產生 search_text
        app(DocumentChunkRepository::class)->replace($document->id, array_map(
            fn (string $content, int $i) => new ChunkDraft($i, $content, 0, mb_strlen($content), 1, 1, null, mb_strlen($content), mb_strlen($content)),
            $contents, array_keys($contents),
        ), 's');

        foreach ($document->chunks()->orderBy('chunk_index')->get() as $chunk) {
            $this->chunks["{$name}{$chunk->chunk_index}"] = $chunk->id;
        }
    }

    private function search(): KeywordSearchRepository
    {
        return app(KeywordSearchRepository::class);
    }

    /**
     * 走正式的查詢路徑：原始問題 → SearchTextNormalizer → ExactTermExtractor → 片語查詢
     *
     * @return list<int>
     */
    private function exactFromQuestion(string $question): array
    {
        $terms = app(ExactTermExtractor::class)->extract(app(SearchTextNormalizer::class)->normalize($question));

        return array_keys($this->search()->exact($terms, 10));
    }

    public function test_arabic_article_number_matches_chinese_numeral_article(): void
    {
        $this->assertSame([$this->chunks['規章0']], array_keys($this->search()->exact(['第12條'], 10)));
    }

    public function test_code_with_hyphen_is_a_phrase_not_an_exclusion(): void
    {
        // 不加雙引號時，「-2026」會被 BOOLEAN MODE 解讀成「排除 2026」
        $this->assertSame([$this->chunks['規章2']], $this->exactFromQuestion('BUG-2026-000183 要附上什麼？'));
    }

    public function test_code_with_single_character_segment_is_found(): void
    {
        // Ch11 實測：ngram_token_size = 2 時，「hris-3」的「3」只有 1 個字，片語查詢整個查不到
        $this->assertSame([$this->chunks['規章3']], $this->exactFromQuestion('HRIS-3 是什麼系統？'));
    }

    public function test_article_with_suffix_number(): void
    {
        $this->assertSame([$this->chunks['規章1']], array_keys($this->search()->exact(['第7條之1'], 10)));
    }

    public function test_natural_language_ranks_matching_chunk_first(): void
    {
        $this->assertSame($this->chunks['規章0'], array_key_first($this->search()->natural('特別休假的申請系統代碼', 10)));
    }

    public function test_deleted_document_is_not_returned(): void
    {
        // 刪除文件時 Chunk 隨 cascade 刪除，關鍵字路徑查的是 MySQL，不會再找到（Dense 路徑由 ChunkPurger 刪除 Points）
        Document::where('name', self::PREFIX.'規章')->delete();

        $this->assertSame([[], []], [$this->exactFromQuestion('BUG-2026-000183 要附上什麼？'), array_keys($this->search()->natural('特別休假的申請系統代碼', 10))]);
    }

    public function test_documents_not_indexed_are_excluded(): void
    {
        $this->assertNotContains($this->chunks['處理中0'], array_keys($this->search()->natural('特別休假', 10)));
    }
}
