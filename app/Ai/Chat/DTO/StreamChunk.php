<?php

namespace App\Ai\Chat\DTO;

/**
 * 串流回答的一段。只有最後一段帶 usage 與 finishReason，其餘為 null。
 */
final readonly class StreamChunk
{
    public function __construct(
        public string $delta,
        public ?Usage $usage = null,
        public ?string $finishReason = null,
    ) {}
}
