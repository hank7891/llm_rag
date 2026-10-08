<?php

namespace App\Rag\Evaluation;

use App\Rag\Retrieval\KeywordOnlyPolicy;
use App\Rag\Retrieval\RetrievalMode;
use App\Rag\Retrieval\RetrievedChunk;
use App\Rag\Retrieval\RetrieveOptions;
use App\Rag\Retrieval\RetrieverService;
use App\Rag\VectorStore\CollectionResolver;
use InvalidArgumentException;

/**
 * 以測試集評估 Retriever：Recall@K（不套用門檻）與套用門檻後「無候選」判斷是否正確。
 */
class RetrievalEvaluator
{
    public const RECALL_AT = [1, 3, 5, 10, 20];

    public function __construct(
        private readonly RetrieverService $retriever,
        private readonly CollectionResolver $resolver,
    ) {}

    /** @return list<TestQuestion> */
    public function load(string $path): array
    {
        $questions = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $i => $line) {
            $row = json_decode($line, true) ?? throw new InvalidArgumentException("{$path} 第 ".($i + 1).' 行不是有效的 JSON。');
            $questions[] = new TestQuestion(
                $row['id'],
                QuestionType::from($row['type']),
                $row['question'],
                array_map(fn (array $e) => new ExpectedSource($e['document'], $e['section']), $row['expected']),
                $row['expected_answer'] ?? null,
            );
        }

        return $questions;
    }

    /** @param list<TestQuestion> $questions */
    /**
     * @param  bool  $applyThreshold  false（Ch08）：Recall 不套門檻，衡量「找不找得到」；
     *                                true（Ch11）：照系統實際行為（Dense 套門檻、資料不足的判斷、keyword_only_policy）計算 Recall
     */
    public function evaluate(
        array $questions,
        ?string $model = null,
        ?float $scoreThreshold = null,
        ?RetrievalMode $mode = null,
        ?KeywordOnlyPolicy $policy = null,
        bool $applyThreshold = false,
        ?bool $rerank = null,
        ?int $rerankCandidates = null,
        ?bool $rerankKeepExact = null,
        ?bool $rerankPrefixMetadata = null,
    ): EvaluationReport {
        if ($questions === []) {
            throw new InvalidArgumentException('測試集沒有任何題目。');
        }

        $threshold = null;
        $contextTopK = null;
        $results = [];

        foreach ($questions as $question) {
            $raw = $this->retriever->retrieve($question->question, new RetrieveOptions($model, max(self::RECALL_AT), $applyThreshold ? $scoreThreshold : null, $applyThreshold, $mode, $policy, $rerank, $rerankCandidates, $rerankKeepExact, $rerankPrefixMetadata));
            $model = $raw->model;
            $threshold = $scoreThreshold ?? $this->retriever->configuredThreshold($model);
            $matches = fn (RetrievedChunk $chunk) => collect($question->expected)->contains(fn (ExpectedSource $e) => $e->matches($chunk));
            $index = collect($raw->chunks)->search($matches);

            // 門檻判斷走實際的檢索路徑（Qdrant score_threshold、預設 Top-K），和下一章組 Context 的方式一致
            $context = $threshold === null ? null : $this->retriever->retrieve($question->question, new RetrieveOptions($model, scoreThreshold: $threshold, mode: $mode, keywordOnlyPolicy: $policy, rerank: $rerank, rerankCandidates: $rerankCandidates, rerankKeepExact: $rerankKeepExact, rerankPrefixMetadata: $rerankPrefixMetadata));
            $contextTopK = $context?->topK;

            $results[] = new QuestionResult(
                $question,
                $index === false ? null : $index + 1,
                $raw->chunks[0] ?? null,
                $index === false ? null : $raw->chunks[$index]->denseScore ?? $raw->chunks[$index]->score,
                $context?->hasCandidates(),
                $context === null ? null : collect($context->chunks)->contains($matches),
                $index === false ? null : $raw->chunks[$index]->retrievalRank,
                $raw->rerankMs,
                $raw->rerankDegraded,
            );
        }

        return new EvaluationReport($model, $this->resolver->name($model), $threshold, $results, $contextTopK, $raw->mode, $policy, $applyThreshold);
    }
}
