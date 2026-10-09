<?php

namespace App\Rag\Answer;

use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Usage;
use App\Rag\Citation\Citation;
use App\Rag\Citation\InvalidRef;
use App\Rag\Conversation\RewriteResult;
use App\Rag\Retrieval\RetrievalResult;

/**
 * 一次 RAG 問答的結果。沒有呼叫 LLM 時 model、usage、llmMs、finishReason 為 null，messages 為空。
 * answer 是移除不合規引用標記後的回答；rawAnswer 是 LLM 的原始輸出（除錯用）。
 */
final readonly class RagAnswer
{
    /**
     * @param  list<Reference>  $references  編號對照表（Ch10 依此顯示來源）
     * @param  list<Message>  $messages  實際送給 LLM 的訊息（除錯用，不放進 API 回應）
     * @param  list<Citation>  $citations  實際被引用、且 MySQL 查得到的來源（依編號排序）
     * @param  list<string>  $sources  來源的顯示文字（同來源合併）
     * @param  list<InvalidRef>  $invalidRefs  被移除的引用標記
     * @param  array<int, string>  $labels  編號 → 來源顯示文字
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
        public ?string $rawAnswer = null,
        public array $citations = [],
        public array $sources = [],
        public array $invalidRefs = [],
        public bool $uncited = false,
        public array $labels = [],
        public ?int $conversationId = null,
        public ?string $originalQuestion = null,
        public ?RewriteResult $rewrite = null,
        public int $historyTurns = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'status' => $this->status->value,
            'conversation_id' => $this->conversationId,
            'original_question' => $this->originalQuestion,
            // 檢索用的問題：改寫成功時為改寫結果，否則為原問題
            'rewritten_question' => $this->rewrite?->question,
            'rewrite_status' => $this->rewrite?->status->value,
            'rewrite_called' => $this->rewrite->called ?? false,
            'rewrite_ms' => $this->rewrite?->latencyMs,
            // 經過 Window 與 history_budget_chars 後實際送出的歷史輪數
            'history_turns' => $this->historyTurns,
            'rewrite_usage' => $this->rewrite?->usage === null ? null : ['input_tokens' => $this->rewrite->usage->inputTokens, 'output_tokens' => $this->rewrite->usage->outputTokens],
            'citations' => array_map(fn (Citation $c) => [
                'ref' => $c->ref,
                'label' => $this->labels[$c->ref] ?? null,
                'document_id' => $c->documentId,
                'document_name' => $c->documentName,
                'chunk_id' => $c->chunkId,
                'section' => $c->section,
                'page_start' => $c->pageStart,
                'page_end' => $c->pageEnd,
                'score' => $c->score,
            ], $this->citations),
            'sources' => $this->sources,
            'warnings' => [
                'invalid_refs' => array_map(fn (InvalidRef $r) => ['raw' => $r->raw, 'reason' => $r->reason->value, 'ref' => $r->ref], $this->invalidRefs),
                'uncited' => $this->uncited,
            ],
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
