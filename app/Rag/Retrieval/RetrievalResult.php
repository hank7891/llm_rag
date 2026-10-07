<?php

namespace App\Rag\Retrieval;

/**
 * 一次檢索的結果與實際使用的條件。
 *
 * chunks 為空代表「無候選」：沒有任何結果超過門檻。下一章依 hasCandidates() 直接回答「資料不足」，不呼叫 LLM。
 */
final readonly class RetrievalResult
{
    /**
     * @param  list<RetrievedChunk>  $chunks  依分數由高到低
     * @param  float|null  $unfilteredTopScore  無候選時，不套門檻的最高分（被門檻擋掉的那一筆）
     */
    public function __construct(
        public array $chunks,
        public string $model,
        public int $topK,
        public ?float $scoreThreshold,
        public ?float $unfilteredTopScore = null,
        public ?float $denseTopScore = null,
        public RetrievalMode $mode = RetrievalMode::Dense,
    ) {}

    public function hasCandidates(): bool
    {
        return $this->chunks !== [];
    }

    /**
     * 不論是否通過門檻的 Dense 最高分（Cosine）；Collection 是空的時為 null。
     * Hybrid 時 chunks 的 score 是 RRF 分數，所以優先使用 denseTopScore，紀錄與校準門檻才有一致的意義。
     */
    public function topScore(): ?float
    {
        // 非 Dense 模式時 chunks 的 score 是 RRF 分數，不能當成 Cosine 使用
        $fallback = $this->mode === RetrievalMode::Dense ? ($this->chunks[0]->score ?? null) : null;

        return $this->denseTopScore ?? $fallback ?? $this->unfilteredTopScore;
    }
}
