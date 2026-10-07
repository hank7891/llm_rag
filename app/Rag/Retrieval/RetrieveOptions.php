<?php

namespace App\Rag\Retrieval;

/**
 * 檢索參數。null 表示使用設定檔的值（模型：目前的 Embedding 模型；topK：rag.retrieval.top_k；
 * scoreThreshold：該模型的門檻）。applyThreshold 為 false 時不套用門檻，用來觀察原始分數與評估 Recall。
 */
final readonly class RetrieveOptions
{
    public function __construct(
        public ?string $model = null,
        public ?int $topK = null,
        public ?float $scoreThreshold = null,
        public bool $applyThreshold = true,
        public ?RetrievalMode $mode = null,
        public ?KeywordOnlyPolicy $keywordOnlyPolicy = null,
    ) {}
}
