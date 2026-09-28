<?php

namespace App\Ai\Chat\DTO;

/**
 * 一次完整（非串流）回答的結果。
 */
final readonly class ChatResult
{
    /**
     * @param  string  $model  實際回答的模型名稱
     * @param  string|null  $finishReason  供應商原始值（如 stop、end_turn、STOP），Ch02 比較各家後再決定是否統一
     */
    public function __construct(
        public string $content,
        public Usage $usage,
        public string $model,
        public ?string $finishReason,
    ) {}
}
