<?php

namespace App\Rag\Evaluation;

use App\Rag\Retrieval\RetrievalResult;
use App\Rag\Retrieval\RetrievedChunk;

/**
 * 一題的評估結果。
 *
 * rank：第一個命中預期來源的名次（1 起算，不套用門檻），沒有命中為 null。
 * preRerankRank：命中段落在重排前（第一階段）的名次；rerankMs：本題 Reranker 耗時（沒有重排時為 null）。
 * hasCandidates：套用門檻後是否有候選；inContext：套用門檻後的 Top-K 是否含預期來源（下一章 LLM 實際看得到）。
 * 沒有設定門檻時兩者皆為 null。
 * expectedRanks：每個期望來源各自的命中名次（All@k 用）；latencyMs：不套門檻那次檢索的耗時；raw：該次檢索的完整結果（輸出原始 JSON 用）。
 * context：套用門檻、預設 Top-K 的那次檢索（線上實際送進 LLM 的段落），沒有設定門檻時為 null。
 */
final readonly class QuestionResult
{
    public function __construct(
        public TestQuestion $question,
        public ?int $rank,
        public ?RetrievedChunk $top,
        public ?float $hitScore,
        public ?bool $hasCandidates,
        public ?bool $inContext,
        public ?int $preRerankRank = null,
        public ?int $rerankMs = null,
        public bool $rerankDegraded = false,
        /** @var list<?int> */
        public array $expectedRanks = [],
        public ?int $latencyMs = null,
        public ?RetrievalResult $raw = null,
        public ?RetrievalResult $context = null,
    ) {}

    /** 所有期望來源都在前 k 名（多條組合題用）；沒有期望來源時為 false */
    public function allWithin(int $k): bool
    {
        return $this->expectedRanks !== [] && collect($this->expectedRanks)->every(fn (?int $rank) => $rank !== null && $rank <= $k);
    }

    public function hitWithin(int $k): bool
    {
        return $this->rank !== null && $this->rank <= $k;
    }
}
