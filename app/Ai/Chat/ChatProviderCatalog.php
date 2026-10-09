<?php

namespace App\Ai\Chat;

/**
 * 給介面顯示的 Chat Provider 清單（名稱、是否地端、模型）。只描述設定，不決定業務流程。
 */
final readonly class ChatProviderCatalog
{
    /** @param array<string, array{label: string, local: bool, model: ?string}> $providers 名稱 → 顯示資訊 */
    public function __construct(private array $providers) {}

    /** @return array<string, array{label: string, local: bool, model: ?string}> */
    public function all(): array
    {
        return $this->providers;
    }
}
