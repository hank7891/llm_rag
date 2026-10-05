<?php

namespace App\Rag\Evaluation;

use App\Rag\Retrieval\RetrievedChunk;

/**
 * 預期應找到的來源：文件名稱 + 條號（不用 chunk_id，重新切段後 chunk_id 會改變）。
 * section 為 null 代表文件沒有章節結構，命中該文件的任何 Chunk 都算。
 */
final readonly class ExpectedSource
{
    public function __construct(
        public string $document,
        public ?string $section,
    ) {}

    /**
     * 條號比對 section 的最後一段且必須完全相同：「第二章 請假 / 第四條」取「第四條」；
     * 用「包含」比對會讓「第7條」誤命中「第7條之1」。
     */
    public function matches(RetrievedChunk $chunk): bool
    {
        if ($chunk->documentName !== $this->document) {
            return false;
        }

        return $this->section === null
            || ($chunk->section !== null && last(explode(' / ', $chunk->section)) === $this->section);
    }
}
