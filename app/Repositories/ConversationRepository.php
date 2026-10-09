<?php

namespace App\Repositories;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Rag\Answer\AnswerStatus;
use App\Rag\Conversation\ConversationRole;
use App\Rag\Conversation\RewriteStatus;
use App\Rag\Conversation\Turn;
use Illuminate\Support\Facades\DB;

class ConversationRepository
{
    public function create(): Conversation
    {
        return Conversation::create();
    }

    public function exists(int $id): bool
    {
        return Conversation::whereKey($id)->exists();
    }

    /**
     * 最近 N 輪（由舊到新）。一輪 = 使用者訊息 + 其後的助理訊息；沒有回答的問題（例如回答時發生錯誤）不算一輪。
     *
     * @return list<Turn>
     */
    public function recentTurns(int $conversationId, int $limit): array
    {
        $messages = ConversationMessage::where('conversation_id', $conversationId)->orderByDesc('id')->limit($limit * 2 + 1)->get()->reverse()->values();

        $turns = [];
        foreach ($messages as $i => $message) {
            $next = $messages[$i + 1] ?? null;
            if ($message->role_key === ConversationRole::User && $next?->role_key === ConversationRole::Assistant) {
                $turns[] = new Turn($message->content, $next->content);
            }
        }

        return array_slice($turns, -$limit);
    }

    /** 一問一答寫在同一個 Transaction，不會只留下半輪 */
    public function addExchange(int $conversationId, string $question, string $rewrittenQuestion, RewriteStatus $rewriteStatus, string $answer, AnswerStatus $status): void
    {
        DB::transaction(function () use ($conversationId, $question, $rewrittenQuestion, $rewriteStatus, $answer, $status) {
            $now = now();
            ConversationMessage::create(['conversation_id' => $conversationId, 'role_key' => ConversationRole::User, 'content' => $question,
                'rewritten_question' => $rewrittenQuestion, 'rewrite_status_key' => $rewriteStatus, 'created_at' => $now]);
            ConversationMessage::create(['conversation_id' => $conversationId, 'role_key' => ConversationRole::Assistant, 'content' => $answer,
                'status_key' => $status, 'created_at' => $now]);
            Conversation::whereKey($conversationId)->update(['updated_at' => $now]);
        });
    }
}
