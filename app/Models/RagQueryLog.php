<?php

namespace App\Models;

use App\Rag\Answer\AnswerStatus;
use App\Rag\Answer\QuerySource;
use App\Rag\Conversation\RewriteStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $question
 * @property float|null $top1_score
 * @property float|null $score_threshold
 * @property int $passed_count
 * @property AnswerStatus $status_key
 * @property QuerySource $source_key
 * @property bool $llm_called
 * @property int $citation_count
 * @property int $invalid_ref_count
 * @property bool $uncited
 * @property string $embedding_model
 * @property string $provider
 * @property string|null $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int $retrieval_ms
 * @property bool $reranked
 * @property bool $rerank_degraded
 * @property int|null $rerank_ms
 * @property int|null $llm_ms
 */
class RagQueryLog extends Model
{
    // 只有 created_at：紀錄寫入後不會修改
    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'question', 'rewritten_question', 'rewrite_status_key', 'rewrite_ms', 'top1_score', 'score_threshold', 'passed_count', 'status_key', 'source_key', 'llm_called', 'citation_count', 'invalid_ref_count', 'uncited', 'embedding_model',
        'provider', 'model', 'input_tokens', 'output_tokens', 'retrieval_ms', 'reranked', 'rerank_degraded', 'rerank_ms', 'llm_ms', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'top1_score' => 'float',
            'score_threshold' => 'float',
            'passed_count' => 'integer',
            'status_key' => AnswerStatus::class,
            'rewrite_status_key' => RewriteStatus::class,
            'rewrite_ms' => 'integer',
            'conversation_id' => 'integer',
            'source_key' => QuerySource::class,
            'llm_called' => 'boolean',
            'citation_count' => 'integer',
            'invalid_ref_count' => 'integer',
            'uncited' => 'boolean',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'retrieval_ms' => 'integer',
            'reranked' => 'boolean',
            'rerank_degraded' => 'boolean',
            'rerank_ms' => 'integer',
            'llm_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
