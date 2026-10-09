<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $provider 建立對話時的回答 Provider（Ch14 起記錄；Ch13 建立的對話為 null）
 */
class Conversation extends Model
{
    protected $fillable = ['provider'];

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }
}
