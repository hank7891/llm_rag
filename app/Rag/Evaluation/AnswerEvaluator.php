<?php

namespace App\Rag\Evaluation;

use App\Ai\Exceptions\LlmException;
use App\Rag\Answer\AnswerOptions;
use App\Rag\Answer\QuerySource;
use App\Rag\Answer\RagAnswerService;

/**
 * 以測試集逐題執行完整的 RAG 流程（rag_query_logs 的來源記為 eval，校準門檻時可排除）。
 */
class AnswerEvaluator
{
    public function __construct(private readonly RagAnswerService $rag) {}

    /**
     * @param  list<TestQuestion>  $questions
     * @return list<AnswerResult>
     */
    public function evaluate(array $questions, ?string $provider = null): array
    {
        return array_map(function (TestQuestion $question) use ($provider) {
            try {
                $answer = $this->rag->answer($question->question, new AnswerOptions($provider, QuerySource::Eval));
            } catch (LlmException $e) {
                // 逾時等錯誤照實記錄，不重試，也不中斷其他題目
                return new AnswerResult($question, null, [], $e::class.'：'.$e->getMessage());
            }

            preg_match_all('/\[(\d+)\]/', $answer->answer, $matches);

            return new AnswerResult($question, $answer, array_values(array_unique(array_map('intval', $matches[1]))));
        }, $questions);
    }
}
