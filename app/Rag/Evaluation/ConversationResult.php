<?php

namespace App\Rag\Evaluation;

use App\Rag\Conversation\RewriteResult;
use App\Rag\Retrieval\RetrievalResult;

/**
 * 一題多輪評估的結果：檢索用的問題、檢索結果中預期段落的名次，以及（--answer 時）回答 LLM 的輸入 Token 數。
 */
final readonly class ConversationResult
{
    public function __construct(
        public ConversationCase $case,
        public string $query,
        public ?RewriteResult $rewrite,
        public RetrievalResult $retrieval,
        public ?int $rank,
        public int $retrievalMs,
        public int $inWindowTurns = 0,
        public int $keptTurns = 0,
        public ?int $answerInputTokens = null,
        public ?string $answer = null,
    ) {}

    public function isNoAnswer(): bool
    {
        return $this->case->expected === [];
    }

    /** 歧義題：追問有多種合理解讀，分開列出、不計入主表 Recall */
    public function isAmbiguous(): bool
    {
        return $this->case->type === ConversationCase::AMBIGUOUS;
    }
}
