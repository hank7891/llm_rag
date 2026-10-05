<?php

namespace Tests\Feature\Rag;

use App\Documents\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Rag\VectorStore\ChunkIndexer;
use App\Rag\VectorStore\ChunkPurger;
use App\Rag\VectorStore\QdrantClient;
use App\Rag\VectorStore\VectorSearcher;
use App\Repositories\DocumentRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 對真實 Qdrant 的整合測試（預設不執行）：php artisan test --group=qdrant
 *
 * 例外於「自動化測試不可呼叫真實 API」：只連本機 Qdrant、使用 test_company_docs 前綴的 Collection，
 * 向量由 FakeEmbeddingProvider 產生（不需要 Ollama），結束時刪除測試用 Collection。
 */
#[Group('qdrant')]
class QdrantIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private const COLLECTION = 'test_company_docs_fake';

    protected function setUp(): void
    {
        parent::setUp();

        Http::allowStrayRequests();
        config()->set('rag.qdrant.collection_prefix', 'test_company_docs');
        $this->app->make(QdrantClient::class)->deleteCollection(self::COLLECTION);
    }

    protected function tearDown(): void
    {
        $this->app->make(QdrantClient::class)->deleteCollection(self::COLLECTION);

        parent::tearDown();
    }

    /** @param list<string> $contents */
    private function chunks(Document $document, array $contents): void
    {
        DocumentChunk::where('document_id', $document->id)->delete();

        foreach ($contents as $i => $content) {
            DocumentChunk::create([
                'document_id' => $document->id, 'chunk_index' => $i, 'content' => $content, 'char_count' => mb_strlen($content), 'token_count' => mb_strlen($content),
                'page_start' => 1, 'page_end' => 1, 'section' => "第{$i}條", 'chunk_strategy' => 'structure_v1:max600:ov15', 'created_at' => now(),
            ]);
        }
    }

    public function test_create_write_count_reindex_and_delete(): void
    {
        $qdrant = $this->app->make(QdrantClient::class);
        $document = (new DocumentRepository)->create(['name' => '整合測試.pdf', 'mime_type' => 'application/pdf', 'path' => 'x', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        $document->forceFill(['status_key' => DocumentStatus::Chunked])->save();
        $filter = ChunkIndexer::documentFilter($document->id);

        // 建立 → 寫入 → 計數
        $this->chunks($document, ['第一條', '第二條', '第三條']);
        $this->app->make(ChunkIndexer::class)->index($document);
        $this->assertSame(3, $qdrant->count(self::COLLECTION, $filter));

        // 重新切段（新的 chunk id）→ 重新索引：舊 id 的 Points 被刪掉，不會留下孤兒
        $this->chunks($document, ['第一條（修正）', '第二條（修正）']);
        $this->app->make(ChunkIndexer::class)->index($document);
        $this->assertSame([2, [$document->id => 2]], [$qdrant->count(self::COLLECTION, $filter), $qdrant->facet(self::COLLECTION, 'document_id')]);

        // 搜尋帶 embedding_model 過濾，能找到剛寫入的資料
        $this->assertCount(2, $this->app->make(VectorSearcher::class)->search('第一條', 5));

        // 刪除
        $this->assertSame([self::COLLECTION], $this->app->make(ChunkPurger::class)->purge($document->id));
        $this->assertSame(0, $qdrant->count(self::COLLECTION, $filter));
    }
}
