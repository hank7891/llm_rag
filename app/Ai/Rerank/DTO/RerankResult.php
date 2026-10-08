<?php

namespace App\Ai\Rerank\DTO;

/**
 * 一次 Rerank 的結果。scores 依分數由高到低排序（不是送出順序）。
 */
final readonly class RerankResult
{
    /** @param list<RerankScore> $scores */
    public function __construct(
        public array $scores,
        public string $model,
        public int $latencyMs,
    ) {}
}
