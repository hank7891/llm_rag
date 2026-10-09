<?php

namespace App\Http\Streaming;

use App\Rag\Answer\RagProgressListener;
use App\Rag\Answer\RagStage;
use App\Rag\Conversation\RewriteResult;
use App\Rag\Retrieval\RetrievalResult;
use Closure;

/**
 * 把 RagAnswerService 的進度轉成 SSE 事件。事件的寫出與斷線偵測由外部傳入，方便測試。
 */
final class SseRagProgressListener implements RagProgressListener
{
    /**
     * @param  Closure(string, array<string, mixed>): void  $send  送出一個事件（事件名稱、data）
     * @param  Closure(): bool  $cancelled  呼叫端是否已離開
     */
    public function __construct(
        private readonly Closure $send,
        private readonly Closure $cancelled,
    ) {}

    public function conversation(?int $conversationId): void
    {
        ($this->send)('conversation', ['conversation_id' => $conversationId]);
    }

    public function stage(RagStage $stage): void
    {
        ($this->send)('stage', ['stage' => $stage->value]);
    }

    public function rewrite(RewriteResult $rewrite): void
    {
        ($this->send)('rewrite', ['rewritten_question' => $rewrite->question, 'rewrite_status' => $rewrite->status->value]);
    }

    public function retrieval(RetrievalResult $retrieval): void
    {
        ($this->send)('retrieval', [
            'candidates' => count($retrieval->chunks),
            'has_candidates' => $retrieval->hasCandidates(),
            'reranked' => $retrieval->reranked,
            'rerank_degraded' => $retrieval->rerankDegraded,
        ]);
    }

    public function delta(string $text): void
    {
        ($this->send)('delta', ['text' => $text]);
    }

    public function cancelled(): bool
    {
        return ($this->cancelled)();
    }
}
