<?php

namespace App\Rag\Evaluation;

/**
 * 測試題型（tests/rag-set/questions.jsonl 的 type）。
 */
enum QuestionType: string
{
    case Exact = 'exact';
    case Paraphrase = 'paraphrase';
    case ExactId = 'exact_id';
    case NoAnswer = 'no_answer';

    public function label(): string
    {
        return match ($this) {
            self::Exact => '用詞相同',
            self::Paraphrase => '換句話說',
            self::ExactId => '精確編號',
            self::NoAnswer => '無答案',
        };
    }
}
