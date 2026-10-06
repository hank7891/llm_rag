<?php

namespace App\Rag\Retrieval;

use App\Ai\Embedding\EmbeddingModels;
use App\Ai\Embedding\EmbeddingService;
use App\Rag\VectorStore\SearchHit;
use App\Rag\VectorStore\VectorSearcher;
use RuntimeException;

/**
 * Retriever：輸入問題，輸出相關 Chunk（Top-K + 相關度門檻）。之後的 Hybrid Search、Reranker 都改這一層。
 */
class RetrieverService
{
    /** @param array<string, float> $scoreThresholds 模型名稱 → 門檻 */
    public function __construct(
        private readonly VectorSearcher $searcher,
        private readonly EmbeddingService $embedding,
        private readonly int $topK,
        private readonly array $scoreThresholds,
    ) {}

    public function retrieve(string $query, RetrieveOptions $options = new RetrieveOptions): RetrievalResult
    {
        $model = EmbeddingModels::canonical($options->model ?? $this->embedding->defaultModel());
        $topK = $options->topK ?? $this->topK;
        $threshold = $options->applyThreshold ? $options->scoreThreshold ?? $this->threshold($model) : null;

        $vector = $this->searcher->embedQuery($query, $model);
        $hits = $this->searcher->searchVector($vector, $model, $topK, $threshold);

        // 沒有候選時 Qdrant 只回傳空陣列，看不到被擋掉的最高分；用同一個向量再取 1 筆（不套門檻），供紀錄與校準門檻
        $unfilteredTopScore = $hits === [] && $threshold !== null
            ? ($this->searcher->searchVector($vector, $model, 1)[0] ?? null)?->score
            : null;

        $chunks = array_map(fn (SearchHit $hit) => new RetrievedChunk(
            (int) $hit->id,
            $hit->payload['document_id'],
            $hit->payload['document_name'],
            $hit->payload['section'],
            $hit->payload['page_start'],
            $hit->payload['page_end'],
            $hit->payload['content'],
            $hit->score,
        ), $hits);

        return new RetrievalResult($chunks, $model, $topK, $threshold, $unfilteredTopScore);
    }

    /** 設定檔中該模型的門檻；沒有設定時為 null */
    public function configuredThreshold(string $model): ?float
    {
        return $this->scoreThresholds[EmbeddingModels::canonical($model)] ?? null;
    }

    private function threshold(string $model): float
    {
        return $this->configuredThreshold($model)
            ?? throw new RuntimeException("Embedding 模型 [{$model}] 尚未設定相關度門檻（config/rag.php 的 retrieval.score_thresholds）。");
    }
}
