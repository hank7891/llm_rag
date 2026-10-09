<?php

namespace App\Rag\Evaluation;

use App\Rag\Conversation\Turn;

/**
 * 多輪測試集的一題（tests/rag-set/conversations.jsonl）。歷史是腳本化的固定內容，評估才可重現。
 * expected 為空代表文件中沒有答案。
 */
final readonly class ConversationCase
{
    public const AMBIGUOUS = 'ambiguous';

    /**
     * @param  list<Turn>  $history  由舊到新
     * @param  list<ExpectedSource>  $expected
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $history,
        public string $question,
        public array $expected,
        public ?string $expectedRewrite = null,
        public ?string $note = null,
    ) {}
}
