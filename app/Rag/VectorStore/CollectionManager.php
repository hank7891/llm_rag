<?php

namespace App\Rag\VectorStore;

use App\Ai\Embedding\EmbeddingModels;
use App\Rag\VectorStore\Exceptions\CollectionSpecMismatchException;

/**
 * 確保 Embedding 模型對應的 Collection 存在且規格正確。
 */
class CollectionManager
{
    private const DISTANCE = 'Cosine';

    public function __construct(
        private readonly QdrantClient $qdrant,
        private readonly CollectionResolver $resolver,
        private readonly EmbeddingModels $models,
    ) {}

    /**
     * 不存在 → 依模型規格建立（Cosine），並建立常用過濾欄位的 Payload Index；
     * 已存在 → 比對 size 與 distance，不一致時丟出例外，不自動刪除重建。
     *
     * @return string Collection 名稱
     */
    public function ensure(string $model): string
    {
        $name = $this->resolver->name($model);
        $size = $this->models->spec($model)['dimension'];
        $existing = $this->qdrant->collection($name);

        if ($existing === null) {
            $this->qdrant->createCollection($name, $size, self::DISTANCE);
            // 依 document_id 刪除、依 embedding_model 過濾都是常用操作，沒有索引時資料多了會變慢
            $this->qdrant->createPayloadIndex($name, 'document_id', 'integer');
            $this->qdrant->createPayloadIndex($name, 'embedding_model', 'keyword');

            return $name;
        }

        $vectors = $existing['config']['params']['vectors'] ?? [];

        if (($vectors['size'] ?? null) !== $size || ($vectors['distance'] ?? null) !== self::DISTANCE) {
            throw CollectionSpecMismatchException::of($name, $model, $size, (int) ($vectors['size'] ?? 0), (string) ($vectors['distance'] ?? 'unknown'));
        }

        return $name;
    }
}
