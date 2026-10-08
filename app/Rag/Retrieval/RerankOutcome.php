<?php

namespace App\Rag\Retrieval;

/**
 * 重排的結果。reranked = false 且 degraded = true：呼叫 Reranker 失敗，chunks 為第一階段的原始排序。
 */
final readonly class RerankOutcome
{
    /** @param list<RetrievedChunk> $chunks */
    public function __construct(
        public array $chunks,
        public bool $reranked,
        public ?int $latencyMs,
        public bool $degraded,
    ) {}
}
