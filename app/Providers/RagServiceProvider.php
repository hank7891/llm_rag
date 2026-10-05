<?php

namespace App\Providers;

use App\Ai\Embedding\EmbeddingService;
use App\Rag\VectorStore\ChunkIndexer;
use App\Rag\VectorStore\CollectionManager;
use App\Rag\VectorStore\CollectionResolver;
use App\Rag\VectorStore\QdrantClient;
use App\Repositories\DocumentChunkRepository;
use Illuminate\Support\ServiceProvider;

/**
 * 向量資料庫元件的組裝處：讀取 config/rag.php 的 qdrant 區塊。
 */
class RagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QdrantClient::class, fn ($app) => new QdrantClient(
            $app['config']->get('rag.qdrant.url'),
            $app['config']->get('rag.qdrant.timeout'),
        ));

        $this->app->bind(CollectionResolver::class, fn ($app) => new CollectionResolver($app['config']->get('rag.qdrant.collection_prefix')));

        $this->app->bind(ChunkIndexer::class, fn ($app) => new ChunkIndexer(
            $app->make(EmbeddingService::class),
            $app->make(CollectionManager::class),
            $app->make(QdrantClient::class),
            $app->make(DocumentChunkRepository::class),
            $app['config']->get('rag.qdrant.upsert_batch_size'),
        ));
    }
}
