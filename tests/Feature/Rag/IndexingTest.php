<?php

namespace Tests\Feature\Rag;

use App\Ai\Embedding\DTO\EmbeddingInputType;
use App\Ai\Embedding\DTO\EmbeddingOptions;
use App\Ai\Embedding\DTO\EmbeddingResult;
use App\Ai\Embedding\Providers\FakeEmbeddingProvider;
use App\Documents\DocumentStatus;
use App\Jobs\IndexDocumentJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Rag\DocumentIndexingService;
use App\Rag\VectorStore\ChunkIndexer;
use App\Rag\VectorStore\ChunkPurger;
use App\Rag\VectorStore\Exceptions\QdrantException;
use App\Rag\VectorStore\VectorSearcher;
use App\Repositories\DocumentRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ChunkIndexer / ChunkPurger / VectorSearcher / IndexDocumentJob。Qdrant 以 Http::fake() 模擬（格式依 Ch07 實測），
 * 向量由 FakeEmbeddingProvider 產生（模型 fake、8 維 → Collection company_docs_fake）。
 */
class IndexingTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = 'http://qdrant.test';

    private const COLLECTION = self::BASE.'/collections/company_docs_fake';

    private FakeEmbeddingProvider $fakeEmbedding;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rag.qdrant.url', self::BASE);
        $this->fakeEmbedding = new FakeEmbeddingProvider;
        $this->app->instance(FakeEmbeddingProvider::class, $this->fakeEmbedding);
    }

    private function fakeQdrant(int $upsertStatus = 200): void
    {
        Http::fake([
            self::COLLECTION => Http::response(['result' => ['config' => ['params' => ['vectors' => ['size' => 8, 'distance' => 'Cosine']]]], 'status' => 'ok']),
            self::COLLECTION.'/points/delete*' => Http::response(['result' => ['status' => 'completed'], 'status' => 'ok']),
            self::COLLECTION.'/points?wait=true' => Http::response($upsertStatus === 200 ? ['result' => ['status' => 'completed'], 'status' => 'ok'] : ['status' => ['error' => 'disk full']], $upsertStatus),
            self::COLLECTION.'/points/query' => Http::response(['result' => ['points' => [['id' => 7, 'version' => 1, 'score' => 0.83, 'payload' => ['section' => '第三條']]]], 'status' => 'ok']),
            self::BASE.'/collections' => Http::response(['result' => ['collections' => [
                ['name' => 'company_docs_bge_m3'], ['name' => 'company_docs_qwen3_embedding_0_6b'], ['name' => 'test_company_docs_fake'], ['name' => 'other_project'],
            ]], 'status' => 'ok']),
            self::BASE.'/collections/*/points/delete*' => Http::response(['result' => ['status' => 'completed'], 'status' => 'ok']),
        ]);
    }

    /** @param list<string> $contents */
    private function document(array $contents = ['第一條 內容', '第二條 內容', '第三條 內容'], DocumentStatus $status = DocumentStatus::Chunked): Document
    {
        $document = (new DocumentRepository)->create([
            'name' => '差勤規章.pdf', 'mime_type' => 'application/pdf', 'path' => 'documents/x.pdf', 'size' => 1, 'sha256' => str_repeat('0', 64),
        ]);
        $document->forceFill(['status_key' => $status])->save();

        foreach ($contents as $i => $content) {
            DocumentChunk::create([
                'document_id' => $document->id, 'chunk_index' => $i, 'content' => $content, 'char_count' => mb_strlen($content), 'token_count' => mb_strlen($content),
                'page_start' => $i + 1, 'page_end' => $i + 2, 'section' => "第{$i}條", 'chunk_strategy' => 'structure_v1:max600:ov15', 'created_at' => now(),
            ]);
        }

        return $document;
    }

    /** @return list<array{string, string}> [method, path] */
    private function writes(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() !== 'GET')
            ->map(fn ($pair) => [$pair[0]->method(), parse_url($pair[0]->url(), PHP_URL_PATH)])->values()->all();
    }

    // ---- ChunkIndexer ----

    public function test_old_points_are_deleted_before_upsert(): void
    {
        $this->fakeQdrant();

        $this->app->make(ChunkIndexer::class)->index($this->document());

        $this->assertSame([['POST', '/collections/company_docs_fake/points/delete'], ['PUT', '/collections/company_docs_fake/points']], $this->writes());
    }

    public function test_delete_filters_by_document_id(): void
    {
        $this->fakeQdrant();
        $document = $this->document();

        $this->app->make(ChunkIndexer::class)->index($document);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/points/delete?wait=true')
            && $r['filter'] === ['must' => [['key' => 'document_id', 'match' => ['value' => $document->id]]]]);
    }

    public function test_point_id_is_chunk_id_and_payload_matches_mysql(): void
    {
        $this->fakeQdrant();
        $document = $this->document();
        $chunk = $document->chunks()->first();

        $this->app->make(ChunkIndexer::class)->index($document);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['points'][0]['id'] === $chunk->id && $r['points'][0]['payload'] === [
            'document_id' => $document->id, 'document_name' => '差勤規章.pdf', 'chunk_id' => $chunk->id, 'chunk_index' => 0,
            'page_start' => 1, 'page_end' => 2, 'section' => '第0條', 'chunk_strategy' => 'structure_v1:max600:ov15',
            'embedding_model' => 'fake', 'content' => '第一條 內容',
        ]);
    }

    public function test_vectors_are_document_embeddings_in_chunk_order(): void
    {
        $this->fakeQdrant();

        $this->app->make(ChunkIndexer::class)->index($this->document());

        $expected = $this->fakeEmbedding->embed(['第二條 內容'], new EmbeddingOptions(EmbeddingInputType::Document))->vectors[0];
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['points'][1]['vector'] === $expected);
    }

    public function test_upsert_is_batched_and_waits(): void
    {
        config()->set('rag.qdrant.upsert_batch_size', 2);
        $this->fakeQdrant();

        $count = $this->app->make(ChunkIndexer::class)->index($this->document());

        $upserts = Http::recorded(fn (Request $r) => $r->method() === 'PUT')->map(fn ($pair) => [count($pair[0]['points']), str_contains($pair[0]->url(), 'wait=true')])->values()->all();
        $this->assertSame([3, [[2, true], [1, true]]], [$count, $upserts]);
    }

    public function test_document_without_chunks_only_clears_old_points(): void
    {
        $this->fakeQdrant();

        $this->assertSame(0, $this->app->make(ChunkIndexer::class)->index($this->document([])));
        $this->assertSame([['POST', '/collections/company_docs_fake/points/delete']], $this->writes());
    }

    // ---- ChunkPurger ----

    public function test_purge_deletes_from_every_project_collection_only(): void
    {
        $this->fakeQdrant();

        $purged = $this->app->make(ChunkPurger::class)->purge(42);

        $this->assertSame(['company_docs_bge_m3', 'company_docs_qwen3_embedding_0_6b'], $purged);
        Http::assertSentCount(3);
    }

    // ---- VectorSearcher ----

    public function test_search_uses_query_embedding_and_model_filter(): void
    {
        $this->fakeQdrant();

        $hits = $this->app->make(VectorSearcher::class)->search('特休沒休完可以延到明年嗎', 5);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/collections/company_docs_fake/points/query')
            && $r['filter'] === ['must' => [['key' => 'embedding_model', 'match' => ['value' => 'fake']]]] && $r['limit'] === 5);
        $this->assertSame(['query', 0.83, '第三條'], [$this->fakeEmbedding->lastOptions->inputType->value, $hits[0]->score, $hits[0]->payload['section']]);
    }

    // ---- 狀態（DocumentIndexingService / IndexDocumentJob） ----

    public function test_chunked_document_becomes_indexed(): void
    {
        $this->fakeQdrant();
        $document = $this->document();

        $this->app->call([new IndexDocumentJob($document->id), 'handle']);

        $this->assertSame(DocumentStatus::Indexed, $document->fresh()->status_key);
    }

    public function test_indexed_document_can_be_reindexed(): void
    {
        $this->fakeQdrant();
        $document = $this->document(status: DocumentStatus::Indexed);

        $this->assertSame(3, $this->app->make(DocumentIndexingService::class)->index($document->id));
    }

    public function test_document_not_chunked_is_not_indexed(): void
    {
        $this->fakeQdrant();
        $document = $this->document(status: DocumentStatus::Parsed);

        $this->assertNull($this->app->make(DocumentIndexingService::class)->index($document->id));
        Http::assertNothingSent();
    }

    public function test_qdrant_failure_is_rethrown_for_retry_and_status_stays_indexing(): void
    {
        $this->fakeQdrant(upsertStatus: 500);
        $document = $this->document();

        try {
            $this->app->call([new IndexDocumentJob($document->id), 'handle']);
            $this->fail('Expected QdrantException.');
        } catch (QdrantException $e) {
            $this->assertSame([DocumentStatus::Indexing, '[qdrant] HTTP 500: disk full'], [$document->fresh()->status_key, $e->getMessage()]);
        }
    }

    public function test_exhausted_retries_mark_document_failed_with_reason(): void
    {
        $document = $this->document(status: DocumentStatus::Indexing);

        (new IndexDocumentJob($document->id))->failed(new QdrantException('[qdrant] HTTP 500: disk full'));

        $this->assertSame(
            [DocumentStatus::Failed, '建立索引時發生錯誤（QdrantException）：[qdrant] HTTP 500: disk full'],
            [$document->fresh()->status_key, $document->fresh()->error_message],
        );
    }

    public function test_indexing_with_non_current_model_does_not_change_status(): void
    {
        // 非目前模型（rag:index --model=）只寫入該模型的 Collection，documents.status 只代表線上使用的模型
        config()->set('llm.embedding.models.other-model', ['dimension' => 8, 'max_tokens' => 8192, 'query_prefix' => '', 'document_prefix' => '']);
        Http::fake([
            self::BASE.'/collections/company_docs_other_model' => Http::response(['result' => ['config' => ['params' => ['vectors' => ['size' => 8, 'distance' => 'Cosine']]]]]),
            self::BASE.'/collections/company_docs_other_model/*' => Http::response(['result' => ['status' => 'completed']]),
        ]);
        $this->app->instance(FakeEmbeddingProvider::class, new class extends FakeEmbeddingProvider
        {
            public function embed(array $texts, EmbeddingOptions $options): EmbeddingResult
            {
                $result = parent::embed($texts, $options);

                return new EmbeddingResult($result->vectors, 'other-model', $result->dimension, $result->inputTokens);
            }
        });
        $document = $this->document(status: DocumentStatus::Indexed);

        $this->app->make(DocumentIndexingService::class)->index($document->id, 'other-model');

        $this->assertSame(DocumentStatus::Indexed, $document->fresh()->status_key);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['points'][0]['payload']['embedding_model'] === 'other-model');
    }

    public function test_job_timeout_is_shorter_than_queue_retry_after(): void
    {
        $job = new IndexDocumentJob(1);

        $this->assertSame([true, true], [$job->timeout < config('queue.connections.database.retry_after'), $job->failOnTimeout]);
    }
}
