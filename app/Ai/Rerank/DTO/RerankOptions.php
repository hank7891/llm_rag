<?php

namespace App\Ai\Rerank\DTO;

/**
 * Rerank 的請求參數。null 代表使用 Provider 的設定值；topN 為 null 時回傳全部。
 */
final readonly class RerankOptions
{
    public function __construct(
        public ?int $topN = null,
        public ?string $model = null,
    ) {}
}
