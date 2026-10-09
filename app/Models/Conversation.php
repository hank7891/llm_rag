<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 */
class Conversation extends Model
{
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }
}
