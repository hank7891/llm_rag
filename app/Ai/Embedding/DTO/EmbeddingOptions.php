<?php

namespace App\Ai\Embedding\DTO;

/**
 * Embedding 的請求參數。model 為 null 時使用 Provider 設定的預設模型。
 */
final readonly class EmbeddingOptions
{
    public function __construct(
        public EmbeddingInputType $inputType,
        public ?string $model = null,
    ) {}
}
