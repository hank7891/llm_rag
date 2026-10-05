<?php

namespace App\Rag\VectorStore;

/**
 * 一筆搜尋結果。score 為 Cosine 相似度，越高越相似。
 */
final readonly class SearchHit
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int|string $id,
        public float $score,
        public array $payload,
    ) {}
}
