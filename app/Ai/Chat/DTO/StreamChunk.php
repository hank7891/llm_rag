<?php

namespace App\Ai\Chat\DTO;

/**
 * 串流回答的一段。只有最後一段帶 usage、finishReason 與 model，其餘為 null。
 */
final readonly class StreamChunk
{
    public function __construct(
        public string $delta,
        public ?Usage $usage = null,
        public ?FinishReason $finishReason = null,
        public ?string $model = null,
    ) {}
}
