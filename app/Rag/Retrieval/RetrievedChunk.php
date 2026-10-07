<?php

namespace App\Rag\Retrieval;

/**
 * 檢索到的一段 Chunk。score 為 Cosine 相似度，越高越相似。
 */
final readonly class RetrievedChunk
{
    public function __construct(
        public int $chunkId,
        public int $documentId,
        public string $documentName,
        public ?string $section,
        public int $pageStart,
        public int $pageEnd,
        public string $content,
        public float $score,
        public ?int $denseRank = null,
        public ?float $denseScore = null,
        public ?int $keywordRank = null,
        public ?float $keywordScore = null,
        public bool $exactMatch = false,
        public ?float $rrfScore = null,
    ) {}
}
