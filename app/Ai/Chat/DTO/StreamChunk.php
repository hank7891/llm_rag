<?php

namespace App\Ai\Chat\DTO;

/**
 * 串流回答的一段。只有最後一段帶 usage、finishReason、model 與 inputTruncated，其餘為 null。
 */
final readonly class StreamChunk
{
    public function __construct(
        public string $delta,
        public ?Usage $usage = null,
        public ?FinishReason $finishReason = null,
        public ?string $model = null,
        public ?bool $inputTruncated = null,
    ) {}
}
