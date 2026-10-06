<?php

namespace App\Rag\Evaluation;

/**
 * 測試集的一題。expected 為空代表文件中沒有答案；expectedAnswer 是給人工判讀的預期答案與判讀標準（選填）。
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
    ) {}
}
