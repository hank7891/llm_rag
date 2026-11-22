<?php

namespace App\Rag\Evaluation;

/**
 * 測試集的一題。expected 為空代表文件中沒有答案（partial 題則列出可引用的條文）；expectedAnswer 是給人工判讀的預期答案與判讀標準（選填）。
 */
final readonly class TestQuestion
{
    /** @param list<ExpectedSource> $expected 命中其中任一即算找對 */
    public function __construct(
        public string $id,
        public QuestionType $type,
        public string $question,
        public array $expected,
        public ?string $expectedAnswer = null,
        public ?Answerability $answerable = null,
    ) {}

    /** 測試集沒有 answerable 欄位時（v1）由題型推得 */
    public function answerability(): Answerability
    {
        return $this->answerable ?? match ($this->type) {
            QuestionType::NoAnswer => Answerability::No,
            QuestionType::Partial => Answerability::Partial,
            default => Answerability::Yes,
        };
    }

    public function isNoAnswer(): bool
    {
        return $this->answerability() === Answerability::No;
    }
}
