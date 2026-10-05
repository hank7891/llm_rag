<?php

namespace App\Rag\VectorStore;

use App\Ai\Embedding\DTO\EmbeddingInputType;
use App\Ai\Embedding\DTO\EmbeddingOptions;
use App\Ai\Embedding\EmbeddingModels;
use App\Ai\Embedding\EmbeddingService;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Repositories\DocumentChunkRepository;

/**
 * 把一份文件的 Chunk 寫入 Qdrant（不處理文件狀態，狀態由 DocumentIndexingService 負責）。
 *
 * 順序：產生向量 → 確保 Collection → 依 document_id 刪除舊 Points → 分批 upsert。
 * 必須先刪後寫：重新切段會產生新的 chunk id，只做 upsert 會留下舊 id 的孤兒 Points。
 * 先產生向量再刪除，讓「刪除後、寫入前」查不到資料的空窗盡量短。
 */
class ChunkIndexer
{
    public function __construct(
        private readonly EmbeddingService $embedding,
        private readonly CollectionManager $collections,
        private readonly QdrantClient $qdrant,
        private readonly DocumentChunkRepository $chunks,
        private readonly int $upsertBatchSize,
    ) {}

    /**
     * @param  string|null  $model  未指定時使用目前設定的 Embedding 模型
     * @return int 寫入的 Point 數
     */
    public function index(Document $document, ?string $model = null): int
    {
        $model = EmbeddingModels::canonical($model ?? $this->embedding->defaultModel());
        $chunks = $this->chunks->forDocument($document->id);
        $collection = $this->collections->ensure($model);

        $vectors = $chunks->isEmpty() ? [] : $this->embedding->embed(
            $chunks->pluck('content')->all(),
            new EmbeddingOptions(EmbeddingInputType::Document, $model),
        )->vectors;

        $this->qdrant->deleteByFilter($collection, self::documentFilter($document->id));

        $points = $chunks->values()->map(fn (DocumentChunk $chunk, int $i) => [
            'id' => $chunk->id,
            'vector' => $vectors[$i],
            'payload' => [
                'document_id' => $document->id,
                'document_name' => $document->name,
                'chunk_id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'page_start' => $chunk->page_start,
                'page_end' => $chunk->page_end,
                'section' => $chunk->section,
                'chunk_strategy' => $chunk->chunk_strategy,
                // 一律由程式帶入實際使用的模型，不接受外部輸入：搜尋時以此過濾
                'embedding_model' => $model,
                'content' => $chunk->content,
            ],
        ])->all();

        foreach (array_chunk($points, max(1, $this->upsertBatchSize)) as $batch) {
            $this->qdrant->upsert($collection, $batch);
        }

        return count($points);
    }

    /** @return array<string, mixed> */
    public static function documentFilter(int $documentId): array
    {
        return ['must' => [['key' => 'document_id', 'match' => ['value' => $documentId]]]];
    }
}
