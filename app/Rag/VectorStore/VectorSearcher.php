<?php

namespace App\Rag\VectorStore;

use App\Ai\Embedding\DTO\EmbeddingInputType;
use App\Ai\Embedding\DTO\EmbeddingOptions;
use App\Ai\Embedding\EmbeddingModels;
use App\Ai\Embedding\EmbeddingService;

/**
 * 向量搜尋（Qdrant 層）。Top-K、門檻等檢索策略由 RetrieverService 決定。
 *
 * 問題以 query 產生向量，搜尋該模型的 Collection，並加上 embedding_model 過濾：
 * 兩個模型都是 1024 維，就算有人把別的模型的向量寫進這個 Collection，Qdrant 也不會報錯，
 * 只能靠過濾條件讓它們搜尋不到。
 */
class VectorSearcher
{
    public function __construct(
        private readonly EmbeddingService $embedding,
        private readonly QdrantClient $qdrant,
        private readonly CollectionResolver $resolver,
    ) {}

    /** @return list<SearchHit> */
    public function search(string $query, int $limit = 5, ?string $model = null, ?float $scoreThreshold = null): array
    {
        $model = EmbeddingModels::canonical($model ?? $this->embedding->defaultModel());

        return $this->searchVector($this->embedQuery($query, $model), $model, $limit, $scoreThreshold);
    }

    /**
     * 以 query 產生問題向量。和 searchVector() 分開，讓同一個問題可以用同一個向量查詢多次。
     *
     * @return list<float>
     */
    public function embedQuery(string $query, string $model): array
    {
        return $this->embedding->embed([$query], new EmbeddingOptions(EmbeddingInputType::Query, $model))->vectors[0];
    }

    /**
     * @param  list<float>  $vector  由 embedQuery() 以同一個模型產生
     * @return list<SearchHit>
     */
    public function searchVector(array $vector, string $model, int $limit, ?float $scoreThreshold = null): array
    {
        return array_map(
            fn (array $point) => new SearchHit($point['id'], $point['score'], $point['payload']),
            $this->qdrant->query($this->resolver->name($model), $vector, $limit, [
                'must' => [['key' => 'embedding_model', 'match' => ['value' => $model]]],
            ], $scoreThreshold),
        );
    }
}
