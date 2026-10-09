<?php

namespace App\Ai\Chat\DTO;

/**
 * 各家共通的請求參數。null 代表使用 Provider 預設值，不送出該參數。
 * Provider 特有參數（如 Ollama num_ctx）預設由各 Provider 從自己的設定讀取；
 * 真的需要逐次覆寫時才用 providerOptions（逃生口），依供應商名稱分組，各 Provider 只讀自己那組。
 */
final readonly class ChatOptions
{
    /**
     * @param  array<string, array<string, mixed>>  $providerOptions  provider 名稱 → 特有參數，例如 ['ollama' => ['num_ctx' => 4096]]
     * @param  int|null  $timeout  非串流請求的總時間（秒）；null 使用 Provider 設定。Ch13 的改寫是短任務，逾時要比回答短
     */
    public function __construct(
        public ?string $model = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public array $providerOptions = [],
        public ?int $timeout = null,
    ) {}

    /** @return array<string, mixed> */
    public function forProvider(string $name): array
    {
        return $this->providerOptions[$name] ?? [];
    }
}
