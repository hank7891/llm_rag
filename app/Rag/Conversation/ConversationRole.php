<?php

namespace App\Rag\Conversation;

/**
 * conversation_messages.role_key。
 */
enum ConversationRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
