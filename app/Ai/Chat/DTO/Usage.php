<?php

namespace App\Ai\Chat\DTO;

/**
 * Token 用量。供應商未回傳時由 Provider 補 0，不讓 null 往上層擴散。
 */
final readonly class Usage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
