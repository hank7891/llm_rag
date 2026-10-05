<?php

namespace App\Rag\Evaluation;

/**
 * 測試集的一題。expected 為空代表文件中沒有答案。
 */
final readonly class TestQuestion
{
    /** @param list<ExpectedSource> $expected 命中其中任一即算找對 */
    public function __construct(
        public string $id,
        public QuestionType $type,
        public string $question,
        public array $expected,
    ) {}
}
