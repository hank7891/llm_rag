<?php

namespace App\Ai\Rerank\Contracts;

use App\Ai\Rerank\DTO\RerankOptions;
use App\Ai\Rerank\DTO\RerankResult;

/**
 * Rerank 能力（Cross-encoder）：把「問題 + 單一段落」一起讀，給出相關度分數。
 * 依能力拆介面：Chat、Embedding Provider 不實作這個介面（Ch01 的設計）。
 */
interface RerankProviderInterface
{
    /** @param list<string> $documents 依送出順序；結果以 index 對應回這個陣列 */
    public function rerank(string $query, array $documents, RerankOptions $options): RerankResult;
}
