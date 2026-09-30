<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property int $page_number
 * @property string $content
 */
class DocumentPage extends Model
{
    protected $fillable = ['document_id', 'page_number', 'content'];

    protected function casts(): array
    {
        return ['page_number' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
