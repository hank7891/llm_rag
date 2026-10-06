<?php

namespace App\Rag\Answer;

/**
 * 編號對照表的一列：LLM 回答中的 [number] 對應哪一段 Chunk。只留在程式端，不送給 LLM。
 */
final readonly class Reference
{
    public function __construct(
        public int $number,
        public int $chunkId,
        public int $documentId,
        public string $documentName,
        public ?string $section,
        public int $pageStart,
        public int $pageEnd,
        public float $score,
    ) {}
}
