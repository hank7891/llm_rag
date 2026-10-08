<?php

namespace App\Ai\Rerank\DTO;

/**
 * 一筆段落的分數。index 是送出時 documents 陣列的位置（從 0 開始）。
 * score 是模型的原始輸出（bge-reranker 為 logit，可以是負數、沒有固定範圍），只能在同一次請求內比較高低，不能和 Cosine 相比。
 */
final readonly class RerankScore
{
    public function __construct(
        public int $index,
        public float $score,
    ) {}
}
