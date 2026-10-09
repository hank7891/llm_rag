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
    public function create(string $provider): Conversation
    {
        return Conversation::create(['provider' => $provider]);
    }

    public function find(int $id): ?Conversation
    {
        return Conversation::find($id);
    }

    /**
     * 最近 N 輪（由舊到新）。一輪 = 使用者訊息 + 其後的助理訊息；沒有回答的問題（例如回答時發生錯誤）不算一輪，
     * 中斷（interrupted）的半截回答也不算，不能進入下一輪的歷史。
     *
     * @return list<Turn>
     */
    public function recentTurns(int $conversationId, int $limit): array
    {
        $turns = [];
        $assistant = null;

        // 由新到舊逐筆讀取，湊滿 N 輪就停；中斷的輪次不算，所以不能用固定的筆數上限
        foreach (ConversationMessage::where('conversation_id', $conversationId)->lazyByIdDesc(20) as $message) {
            if (count($turns) >= $limit) {
                break;
            }

            if ($message->role_key === ConversationRole::Assistant) {
                $assistant = $message;

                continue;
            }

            if ($assistant !== null && $assistant->status_key !== AnswerStatus::Interrupted) {
                $turns[] = new Turn($message->content, $assistant->content);
            }
            $assistant = null;
        }

        return array_reverse($turns);
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
