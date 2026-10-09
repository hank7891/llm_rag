<?php

namespace App\Rag\Conversation;

/**
 * 對話中的一輪：使用者的原始問題與助理的回答。
 */
final readonly class Turn
{
    public function __construct(
        public string $question,
        public string $answer,
    ) {}
}
