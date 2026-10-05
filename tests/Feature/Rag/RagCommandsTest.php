<?php

namespace Tests\Feature\Rag;

use App\Documents\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Repositories\DocumentRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RagCommandsTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = 'http://qdrant.test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rag.qdrant.url', self::BASE);
    }

    private function document(int $chunks): Document
    {
        $document = (new DocumentRepository)->create(['name' => '規章.pdf', 'mime_type' => 'application/pdf', 'path' => 'x', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        $document->forceFill(['status_key' => DocumentStatus::Indexed])->save();

        for ($i = 0; $i < $chunks; $i++) {
            DocumentChunk::create(['document_id' => $document->id, 'chunk_index' => $i, 'content' => "第{$i}條", 'char_count' => 3, 'token_count' => 3,
                'page_start' => 1, 'page_end' => 1, 'section' => null, 'chunk_strategy' => 's', 'created_at' => now()]);
        }

        return $document;
    }

    private function fakeCollection(array $facet): void
    {
        Http::fake([
            self::BASE.'/collections/company_docs_fake' => Http::response(['result' => ['config' => ['params' => ['vectors' => ['size' => 8, 'distance' => 'Cosine']]]]]),
            self::BASE.'/collections/company_docs_fake/facet' => Http::response(['result' => ['hits' => $facet]]),
            self::BASE.'/collections/company_docs_fake/points/count' => Http::response(['result' => ['count' => array_sum(array_column($facet, 'count'))]]),
        ]);
    }

    public function test_index_check_passes_when_counts_match(): void
    {
        $document = $this->document(3);
        $this->fakeCollection([['value' => $document->id, 'count' => 3]]);

        $this->artisan('rag:index-check')->expectsOutputToContain('孤兒 Points：無')->assertSuccessful();
    }

    public function test_index_check_reports_mismatch_and_orphans(): void
    {
        $document = $this->document(3);
        $this->fakeCollection([['value' => $document->id, 'count' => 5], ['value' => 999999, 'count' => 2]]);

        $this->artisan('rag:index-check')
            ->expectsOutputToContain('不一致')
            ->expectsOutputToContain('孤兒 Points（document_id 已不存在於 MySQL）')
            ->assertFailed();
    }

    public function test_index_check_ignores_facet_values_with_zero_points(): void
    {
        // 實測：刪除文件後，facet 仍回傳 {value: 該 id, count: 0}
        $document = $this->document(3);
        $this->fakeCollection([['value' => $document->id, 'count' => 3], ['value' => 999999, 'count' => 0]]);

        $this->artisan('rag:index-check')->expectsOutputToContain('孤兒 Points：無')->assertSuccessful();
    }

    public function test_collections_lists_only_project_collections(): void
    {
        Http::fake([
            self::BASE.'/collections' => Http::response(['result' => ['collections' => [['name' => 'company_docs_bge_m3'], ['name' => 'other_project']]]]),
            self::BASE.'/collections/company_docs_bge_m3' => Http::response(['result' => ['status' => 'green', 'points_count' => 53, 'indexed_vectors_count' => 0,
                'config' => ['params' => ['vectors' => ['size' => 1024, 'distance' => 'Cosine']]]]]),
        ]);

        $this->artisan('rag:collections')
            ->expectsTable(['Collection', '維度', 'distance', 'points_count', 'indexed_vectors_count', 'status'], [['company_docs_bge_m3', 1024, 'Cosine', 53, 0, 'green']])
            ->assertSuccessful();
    }

    public function test_index_requires_document_or_all(): void
    {
        $this->artisan('rag:index')->assertFailed();
    }

    public function test_index_with_non_current_model_announces_status_is_untouched(): void
    {
        config()->set('llm.embedding.models.other-model', ['dimension' => 8, 'max_tokens' => 8192, 'query_prefix' => '', 'document_prefix' => '']);

        $this->artisan('rag:index', ['document' => 999999, '--model' => 'other-model'])->expectsOutputToContain('非目前設定，不修改文件狀態');
    }
}
