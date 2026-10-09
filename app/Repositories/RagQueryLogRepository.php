<?php

namespace App\Repositories;

use App\Models\RagQueryLog;
use App\Rag\Answer\QuerySource;
use App\Rag\Answer\RagAnswer;
use Carbon\CarbonInterface;

class RagQueryLogRepository
{
    /**
     * 統計用（rag:stats）：指定期間的問答紀錄，可排除評估（eval）的提問。
     *
     * @param  list<QuerySource>  $excludeSources
     * @return list<RagQueryLog>
     */
    public function between(?CarbonInterface $since, ?CarbonInterface $until, array $excludeSources = []): array
    {
        return RagQueryLog::query()
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->when($until, fn ($q) => $q->where('created_at', '<', $until))
            ->when($excludeSources !== [], fn ($q) => $q->whereNotIn('source_key', array_map(fn (QuerySource $s) => $s->value, $excludeSources)))
            ->orderBy('id')
            ->get()
            ->all();
    }

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
            'reranked' => $answer->retrieval->reranked,
            'rerank_degraded' => $answer->retrieval->rerankDegraded,
            'rerank_ms' => $answer->retrieval->rerankMs,
            'llm_ms' => $answer->llmMs,
            'created_at' => now(),
        ]);
    }
}
