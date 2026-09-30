<?php

namespace App\Models;

use App\Documents\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $mime_type
 * @property string $path
 * @property int $size
 * @property string $sha256
 * @property int|null $page_count
 * @property DocumentStatus $status_key
 * @property string|null $error_message
 */
class Document extends Model
{
    protected $fillable = ['name', 'mime_type', 'path', 'size', 'sha256', 'page_count', 'status_key', 'error_message'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'page_count' => 'integer',
            'status_key' => DocumentStatus::class,
        ];
    }

    public function pages(): HasMany
    {
        return $this->hasMany(DocumentPage::class)->orderBy('page_number');
    }
}
