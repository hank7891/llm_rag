<?php

namespace App\Rag\Answer;

use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Usage;
use App\Rag\Retrieval\RetrievalResult;

/**
 * 一次 RAG 問答的結果。沒有呼叫 LLM 時 model、usage、llmMs、finishReason 為 null，messages 為空。
 */
final readonly class RagAnswer
{
    /**
     * @param  list<Reference>  $references  編號對照表（Ch10 依此顯示來源）
     * @param  list<Message>  $messages  實際送給 LLM 的訊息（除錯用，不放進 API 回應）
     */
    public function __construct(
        public string $answer,
        public AnswerStatus $status,
        public bool $llmCalled,
        public array $references,
        public int $droppedChunks,
        public string $provider,
        public ?string $model,
        public ?Usage $usage,
        public int $retrievalMs,
        public ?int $llmMs,
        public RetrievalResult $retrieval,
        public array $messages = [],
        public ?FinishReason $finishReason = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'status' => $this->status->value,
            'llm_called' => $this->llmCalled,
            'references' => array_map(fn (Reference $r) => [
                'number' => $r->number,
                'chunk_id' => $r->chunkId,
                'document_id' => $r->documentId,
                'document_name' => $r->documentName,
                'section' => $r->section,
                'page_start' => $r->pageStart,
                'page_end' => $r->pageEnd,
                'score' => $r->score,
            ], $this->references),
            'dropped_chunks' => $this->droppedChunks,
            'provider' => $this->provider,
            'model' => $this->model,
            'finish_reason' => $this->finishReason?->value,
            'usage' => $this->usage === null ? null : ['input_tokens' => $this->usage->inputTokens, 'output_tokens' => $this->usage->outputTokens],
            'timing' => ['retrieval_ms' => $this->retrievalMs, 'llm_ms' => $this->llmMs],
        ];
    }
}
