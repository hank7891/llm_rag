<?php

namespace App\Rag\Answer;

/**
 * 送給 LLM 的參考資料，與程式端保留的編號對照表。
 */
final readonly class ReferenceContext
{
    /** @param list<Reference> $references 依編號排序 */
    public function __construct(
        public string $text,
        public array $references,
        public int $droppedChunks,
    ) {}
}
