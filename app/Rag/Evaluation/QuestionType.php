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

    // Ch09：需要組合條文、計算或判斷才能回答
    case Reasoning = 'reasoning';

    // Ch09：一部分有答案、一部分資料不足（正確與否只能人工判讀）
    case Partial = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::Exact => '用詞相同',
            self::Paraphrase => '換句話說',
            self::ExactId => '精確編號',
            self::NoAnswer => '無答案',
            self::Reasoning => '推理',
            self::Partial => '部分有答案',
        };
    }
}
