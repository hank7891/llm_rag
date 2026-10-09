<?php

namespace App\Models;

use App\Rag\Answer\AnswerStatus;
use App\Rag\Conversation\ConversationRole;
use App\Rag\Conversation\RewriteStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $conversation_id
 * @property ConversationRole $role_key
 * @property string $content
 * @property string|null $rewritten_question
 * @property RewriteStatus|null $rewrite_status_key
 * @property AnswerStatus|null $status_key
 */
class ConversationMessage extends Model
{
    // 只有 created_at：訊息寫入後不會修改
    public $timestamps = false;

    protected $fillable = ['conversation_id', 'role_key', 'content', 'rewritten_question', 'rewrite_status_key', 'status_key', 'created_at'];

    protected function casts(): array
    {
        return [
            'role_key' => ConversationRole::class,
            'rewrite_status_key' => RewriteStatus::class,
            'status_key' => AnswerStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
