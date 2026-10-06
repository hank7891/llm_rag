<?php

namespace App\Models;

use App\Rag\Answer\AnswerStatus;
use App\Rag\Answer\QuerySource;
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
 * @property string $embedding_model
 * @property string $provider
 * @property string|null $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int $retrieval_ms
 * @property int|null $llm_ms
 */
class RagQueryLog extends Model
{
    // 只有 created_at：紀錄寫入後不會修改
    public $timestamps = false;

    protected $fillable = [
        'question', 'top1_score', 'score_threshold', 'passed_count', 'status_key', 'source_key', 'llm_called', 'embedding_model',
        'provider', 'model', 'input_tokens', 'output_tokens', 'retrieval_ms', 'llm_ms', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'top1_score' => 'float',
            'score_threshold' => 'float',
            'passed_count' => 'integer',
            'status_key' => AnswerStatus::class,
            'source_key' => QuerySource::class,
            'llm_called' => 'boolean',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'retrieval_ms' => 'integer',
            'llm_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
