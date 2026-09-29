<?php

namespace App\Ai\Chat\DTO;

/**
 * 一次完整（非串流）回答的結果。
 */
final readonly class ChatResult
{
    /**
     * @param  string  $model  實際回答的模型名稱
     */
    public function __construct(
        public string $content,
        public Usage $usage,
        public string $model,
        public FinishReason $finishReason,
    ) {}
}
