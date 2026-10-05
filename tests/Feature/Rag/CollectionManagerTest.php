<?php

namespace Tests\Feature\Rag;

use App\Rag\VectorStore\CollectionManager;
use App\Rag\VectorStore\Exceptions\CollectionSpecMismatchException;
use App\Rag\VectorStore\Exceptions\QdrantException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectionManagerTest extends TestCase
{
    private const BASE = 'http://qdrant.test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rag.qdrant.url', self::BASE);
    }

    /** 依實測格式回傳 Collection 資訊 */
    private function existing(int $size, string $distance = 'Cosine'): array
    {
        return ['result' => ['status' => 'green', 'points_count' => 0, 'config' => ['params' => ['vectors' => ['size' => $size, 'distance' => $distance]]]], 'status' => 'ok'];
    }

    private function ensure(string $model = 'bge-m3'): string
    {
        return $this->app->make(CollectionManager::class)->ensure($model);
    }

    public function test_missing_collection_is_created_with_model_dimension_and_cosine(): void
    {
        Http::fake([
            self::BASE.'/collections/company_docs_bge_m3' => Http::sequence()
                ->push(['status' => ['error' => 'Not found: Collection `company_docs_bge_m3` doesn\'t exist!']], 404)
                ->push(['result' => true, 'status' => 'ok']),
            self::BASE.'/collections/company_docs_bge_m3/index*' => Http::response(['result' => ['status' => 'completed'], 'status' => 'ok']),
        ]);

        $this->assertSame('company_docs_bge_m3', $this->ensure());

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === self::BASE.'/collections/company_docs_bge_m3'
            && $r['vectors'] === ['size' => 1024, 'distance' => 'Cosine']);
    }

    public function test_payload_indexes_are_created_with_new_collection(): void
    {
        Http::fake([
            self::BASE.'/collections/company_docs_bge_m3' => Http::sequence()->push([], 404)->push(['result' => true]),
            self::BASE.'/collections/company_docs_bge_m3/index*' => Http::response(['result' => ['status' => 'completed']]),
        ]);

        $this->ensure();

        $indexes = Http::recorded(fn (Request $r) => str_contains($r->url(), '/index'))->map(fn ($pair) => [$pair[0]['field_name'], $pair[0]['field_schema']])->values()->all();
        $this->assertSame([['document_id', 'integer'], ['embedding_model', 'keyword']], $indexes);
    }

    public function test_index_creation_waits_for_completion(): void
    {
        Http::fake([
            self::BASE.'/collections/company_docs_bge_m3' => Http::sequence()->push([], 404)->push(['result' => true]),
            self::BASE.'/collections/company_docs_bge_m3/index*' => Http::response(['result' => ['status' => 'completed']]),
        ]);

        $this->ensure();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/index?wait=true'));
    }

    public function test_existing_collection_with_matching_spec_is_reused(): void
    {
        Http::fake([self::BASE.'/collections/company_docs_bge_m3' => Http::response($this->existing(1024))]);

        $this->ensure();

        Http::assertSentCount(1);
    }

    public function test_size_mismatch_is_rejected_without_deleting(): void
    {
        Http::fake([self::BASE.'/*' => Http::response($this->existing(768))]);

        try {
            $this->ensure();
            $this->fail('Expected CollectionSpecMismatchException.');
        } catch (CollectionSpecMismatchException $e) {
            $this->assertStringContainsString('expected size 1024 / Cosine, found size 768 / Cosine', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' || $r->method() === 'PUT');
    }

    public function test_distance_mismatch_is_rejected(): void
    {
        Http::fake([self::BASE.'/*' => Http::response($this->existing(1024, 'Dot'))]);

        $this->expectException(CollectionSpecMismatchException::class);

        $this->ensure();
    }

    public function test_each_model_gets_its_own_collection(): void
    {
        Http::fake([self::BASE.'/*' => Http::response($this->existing(1024))]);

        $this->assertSame(['company_docs_bge_m3', 'company_docs_qwen3_embedding_0_6b'], [$this->ensure('bge-m3'), $this->ensure('qwen3-embedding:0.6b')]);
    }

    public function test_qdrant_error_message_is_included(): void
    {
        // 實測格式：{"status": {"error": "..."}}
        Http::fake([self::BASE.'/*' => Http::response(['status' => ['error' => 'Service internal error: disk full']], 500)]);

        $this->expectException(QdrantException::class);
        $this->expectExceptionMessage('[qdrant] HTTP 500: Service internal error: disk full');

        $this->ensure();
    }
}
