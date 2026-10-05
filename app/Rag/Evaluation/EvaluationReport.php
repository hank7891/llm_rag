<?php

namespace App\Rag\Evaluation;

/**
 * 一次評估的結果與統計。Recall 只計算有答案的題目，而且不套用門檻（衡量「有沒有找對」）。
 */
final readonly class EvaluationReport
{
    /** @param list<QuestionResult> $results */
    public function __construct(
        public string $model,
        public string $collection,
        public ?float $scoreThreshold,
        public array $results,
        public ?int $contextTopK = null,
    ) {}

    /** 沒有該題型的有答案題時為 null */
    public function recall(int $k, ?QuestionType $type = null): ?float
    {
        $answerable = array_filter($this->answerable(), fn (QuestionResult $r) => $type === null || $r->question->type === $type);

        return $answerable === [] ? null : count(array_filter($answerable, fn (QuestionResult $r) => $r->hitWithin($k))) / count($answerable);
    }

    /** @return list<QuestionResult> */
    public function answerable(): array
    {
        return array_values(array_filter($this->results, fn (QuestionResult $r) => $r->question->type !== QuestionType::NoAnswer));
    }

    /** @return list<QuestionResult> */
    public function noAnswer(): array
    {
        return array_values(array_filter($this->results, fn (QuestionResult $r) => $r->question->type === QuestionType::NoAnswer));
    }

    /**
     * @param  list<QuestionResult>  $results
     * @return list<float>
     */
    public static function topScores(array $results): array
    {
        return array_values(array_filter(array_map(fn (QuestionResult $r) => $r->top?->score, $results), fn (?float $s) => $s !== null));
    }

    /**
     * 套用門檻後判斷錯誤的題目：有答案題被判為無候選，或無答案題仍有候選。
     *
     * @return list<QuestionResult>
     */
    public function thresholdErrors(): array
    {
        return array_values(array_filter($this->results, fn (QuestionResult $r) => $r->hasCandidates !== null
            && $r->hasCandidates === ($r->question->type === QuestionType::NoAnswer)));
    }
}
