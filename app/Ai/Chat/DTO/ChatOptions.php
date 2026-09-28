<?php

namespace App\Ai\Chat\DTO;

/**
 * 各家共通的請求參數。null 代表使用 Provider 預設值，不送出該參數。
 * Provider 特有參數（如 Ollama num_ctx、qwen3 think）不放這裡，由各 Provider 從自己的設定讀取。
 */
final readonly class ChatOptions
{
    public function __construct(
        public ?string $model = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
    ) {}
}
