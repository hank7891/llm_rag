<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property int $chunk_index
 * @property string $content
 * @property int $char_count
 * @property int $token_count
 * @property int $page_start
 * @property int $page_end
 * @property string|null $section
 * @property string $chunk_strategy
 */
class DocumentChunk extends Model
{
    // 只有 created_at：Chunk 不會被修改，重新切段是整批刪除後重建
    public $timestamps = false;

    protected $fillable = ['document_id', 'chunk_index', 'content', 'char_count', 'token_count', 'page_start', 'page_end', 'section', 'chunk_strategy', 'created_at'];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'char_count' => 'integer',
            'token_count' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
