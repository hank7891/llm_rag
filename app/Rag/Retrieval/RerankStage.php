<?php

namespace App\Rag\Retrieval;

use App\Ai\Exceptions\LlmException;
use App\Ai\Rerank\DTO\RerankOptions;
use App\Ai\Rerank\RerankService;
use Psr\Log\LoggerInterface;

/**
 * 第二階段檢索：把第一階段（Dense / Hybrid）的候選交給 Cross-encoder 重新排序，再取 Top-K。
 * Reranker 只負責排序，不決定有沒有資料（資料不足由 RetrieverService 的判斷負責，分數尺度也和 Cosine 不同）。
 */
class RerankStage
{
    public function __construct(
        private readonly RerankService $reranker,
        private readonly LoggerInterface $logger,
        private readonly ?float $minScore,
    ) {}

    /**
     * @param  list<RetrievedChunk>  $candidates  第一階段的排序結果
     * @param  bool  $keepExact  精確命中的段落保證保留：Cross-encoder 對「BUG-2026-000183」這類編號不一定敏感
     * @param  bool  $prefixMetadata  送出時在段落前加上「文件名稱 條號：」
     */
    public function rerank(string $query, array $candidates, int $topK, bool $keepExact, bool $prefixMetadata): RerankOutcome
    {
        // 送 content 原文，不送 search_text（小寫、去空白後的文字會讓 Cross-encoder 判斷變差）
        $documents = array_map(fn (RetrievedChunk $c) => $prefixMetadata ? trim("{$c->documentName} ".($c->section ?? '')).'：'.$c->content : $c->content, $candidates);

        try {
            $result = $this->reranker->rerank($query, $documents, new RerankOptions);
        } catch (LlmException $e) {
            // 降級：服務沒啟動、逾時或格式錯誤時，退回第一階段的排序，問答不中斷
            $this->logger->warning('rerank.degraded', ['exception' => $e::class, 'message' => $e->getMessage(), 'candidates' => count($candidates)]);

            return new RerankOutcome(array_slice($candidates, 0, $topK), false, null, true);
        }

        // 依 index 對回候選（不假設回傳順序），記錄重排後的名次與分數
        $ranked = [];
        foreach ($result->scores as $rank => $score) {
            $ranked[] = $candidates[$score->index]->withRerank($rank + 1, $score->score);
        }

        // 實驗參數（預設 null 不啟用）：低於分數的段落不採用；精確命中不受影響
        if ($this->minScore !== null) {
            $ranked = array_values(array_filter($ranked, fn (RetrievedChunk $c) => $c->rerankScore >= $this->minScore || ($keepExact && $c->exactMatch)));
        }

        return new RerankOutcome($this->select($ranked, $topK, $keepExact), true, $result->latencyMs, false);
    }

    /**
     * 取 Top-K。keepExact 時精確命中一定入選（超過 K 筆時取分數最高的 K 筆），其餘名額依 Reranker 分數；入選後仍依分數排序。
     *
     * @param  list<RetrievedChunk>  $ranked  依 Reranker 分數由高到低
     * @return list<RetrievedChunk>
     */
    private function select(array $ranked, int $topK, bool $keepExact): array
    {
        if (! $keepExact) {
            return array_slice($ranked, 0, $topK);
        }

        $exact = array_slice(array_values(array_filter($ranked, fn (RetrievedChunk $c) => $c->exactMatch)), 0, $topK);
        $others = array_slice(array_values(array_filter($ranked, fn (RetrievedChunk $c) => ! $c->exactMatch)), 0, $topK - count($exact));
        $selected = array_merge($exact, $others);
        usort($selected, fn (RetrievedChunk $a, RetrievedChunk $b) => $a->rerankRank <=> $b->rerankRank);

        return $selected;
    }
}
