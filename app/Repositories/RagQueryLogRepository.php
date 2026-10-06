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
            'question' => $question,
            'top1_score' => $answer->retrieval->topScore(),
            'score_threshold' => $answer->retrieval->scoreThreshold,
            'passed_count' => count($answer->retrieval->chunks),
            'status_key' => $answer->status,
            'source_key' => $source,
            'llm_called' => $answer->llmCalled,
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
