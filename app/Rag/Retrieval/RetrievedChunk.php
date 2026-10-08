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
        public ?int $retrievalRank = null,
        public ?int $rerankRank = null,
        public ?float $rerankScore = null,
    ) {}

    /** 重排後的副本：保留第一階段的所有欄位，加上 Reranker 的名次與分數 */
    public function withRerank(int $rank, float $score): self
    {
        return new self(
            $this->chunkId, $this->documentId, $this->documentName, $this->section, $this->pageStart, $this->pageEnd, $this->content, $this->score,
            $this->denseRank, $this->denseScore, $this->keywordRank, $this->keywordScore, $this->exactMatch, $this->rrfScore, $this->retrievalRank, $rank, $score,
        );
    }
}
