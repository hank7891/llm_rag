<?php

namespace App\Rag\VectorStore;

/**
 * 從所有本專案的 Collection（company_docs_*）刪除某份文件的 Points。
 * 不只清目前使用的模型：Ch08 會有多個模型的 Collection 並存，只清一個，另一個就會殘留。
 */
class ChunkPurger
{
    public function __construct(
        private readonly QdrantClient $qdrant,
        private readonly CollectionResolver $resolver,
    ) {}

    /** @return list<string> 執行刪除的 Collection */
    public function purge(int $documentId): array
    {
        $collections = array_values(array_filter($this->qdrant->collections(), fn (string $name) => $this->resolver->owns($name)));

        foreach ($collections as $collection) {
            $this->qdrant->deleteByFilter($collection, ChunkIndexer::documentFilter($documentId));
        }

        return $collections;
    }
}
