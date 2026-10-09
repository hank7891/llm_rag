<?php

namespace App\Repositories;

use App\Models\RagQueryLog;
use App\Rag\Answer\QuerySource;
use App\Rag\Answer\RagAnswer;

class RagQueryLogRepository
{
    public function record(string $question, RagAnswer $answer, QuerySource $source): RagQueryLog
    {
        return RagQueryLog::create([
            'conversation_id' => $answer->conversationId,
            'question' => $question,
            'rewritten_question' => $answer->rewrite?->question,
            'rewrite_status_key' => $answer->rewrite?->status,
            'rewrite_ms' => $answer->rewrite?->latencyMs,
            'top1_score' => $answer->retrieval->topScore(),
            'score_threshold' => $answer->retrieval->scoreThreshold,
            'passed_count' => count($answer->retrieval->chunks),
            'status_key' => $answer->status,
            'source_key' => $source,
            'llm_called' => $answer->llmCalled,
            'citation_count' => count($answer->citations),
            'invalid_ref_count' => count($answer->invalidRefs),
            'uncited' => $answer->uncited,
            'embedding_model' => $answer->retrieval->model,
            'provider' => $answer->provider,
            'model' => $answer->model,
            'input_tokens' => $answer->usage?->inputTokens,
            'output_tokens' => $answer->usage?->outputTokens,
            'retrieval_ms' => $answer->retrievalMs,
            'llm_ms' => $answer->llmMs,
            'created_at' => now(),
        ]);
    }
}
