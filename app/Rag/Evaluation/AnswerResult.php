<?php

namespace App\Rag\Evaluation;

use App\Rag\Answer\RagAnswer;

/**
 * 一題端到端問答的結果與自動檢查。答案是否正確由使用者人工判讀，不在這裡判斷。
 * 呼叫 LLM 失敗（例如逾時）時 answer 為 null、error 記錄原因，不重試。
 */
final readonly class AnswerResult
{
    /** @param list<int> $citations 回答中出現的 [n] 編號（依出現順序、不重複） */
    public function __construct(
        public TestQuestion $question,
        public ?RagAnswer $answer,
        public array $citations,
        public ?string $error = null,
    ) {}

    /**
     * 無答案題：必須是資料不足（不論哪一道防線擋下）。
     * 部分有答案：有呼叫 LLM 且標示 [n]（回答了哪一部分、資料不足的部分是否講明，只能人工判讀）。
     * 其他有答案題：不能回答資料不足，且至少標示一個 [n]。
     */
    public function passed(): bool
    {
        if ($this->answer === null) {
            return false;
        }

        return match ($this->question->type) {
            QuestionType::NoAnswer => $this->answer->status->isInsufficient(),
            QuestionType::Partial => $this->answer->llmCalled && $this->citations !== [],
            default => ! $this->answer->status->isInsufficient() && $this->citations !== [],
        };
    }
}
