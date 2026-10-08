<?php

namespace App\Rag\Evaluation;

use App\Rag\Retrieval\KeywordOnlyPolicy;
use App\Rag\Retrieval\RetrievalMode;

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
        public RetrievalMode $mode = RetrievalMode::Dense,
        public ?KeywordOnlyPolicy $keywordOnlyPolicy = null,
        public bool $thresholdApplied = false,
    ) {}

    /** 沒有該題型的有答案題時為 null */
    public function recall(int $k, ?QuestionType $type = null): ?float
    {
        $answerable = array_filter($this->answerable(), fn (QuestionResult $r) => $type === null || $r->question->type === $type);

        return $answerable === [] ? null : count(array_filter($answerable, fn (QuestionResult $r) => $r->hitWithin($k))) / count($answerable);
    }

    /**
     * MRR：正確段落名次的倒數平均（第 1 名得 1、第 2 名得 0.5、沒找到得 0）。Recall@5 看不出第 4 名升到第 1 名，MRR 看得出來。
     * 只計算有答案的題目；沒有該題型時為 null。
     */
    public function mrr(?QuestionType $type = null): ?float
    {
        $answerable = array_filter($this->answerable(), fn (QuestionResult $r) => $type === null || $r->question->type === $type);

        return $answerable === [] ? null : array_sum(array_map(fn (QuestionResult $r) => $r->rank === null ? 0 : 1 / $r->rank, $answerable)) / count($answerable);
    }

    /**
     * Reranker 耗時的百分位數（只計算有實際重排的題目）。沒有重排時為 null。
     */
    public function rerankLatencyPercentile(int $percentile): ?int
    {
        $ms = array_values(array_filter(array_map(fn (QuestionResult $r) => $r->rerankMs, $this->results), fn (?int $v) => $v !== null));
        sort($ms);

        return $ms === [] ? null : $ms[max(0, (int) ceil($percentile / 100 * count($ms)) - 1)];
    }

    public function rerankDegradedCount(): int
    {
        return count(array_filter($this->results, fn (QuestionResult $r) => $r->rerankDegraded));
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
        // Hybrid 時 score 是 RRF 分數，分布要看 Dense 的 Cosine；只被關鍵字找到的 Top-1 沒有 Cosine，不列入
        return array_values(array_filter(array_map(fn (QuestionResult $r) => $r->top?->rrfScore === null ? $r->top?->score : $r->top->denseScore, $results), fn (?float $s) => $s !== null));
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
