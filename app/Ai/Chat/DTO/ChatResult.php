<?php

namespace App\Ai\Chat\DTO;

/**
 * 一次完整（非串流）回答的結果。
 */
final readonly class ChatResult
{
    /**
     * @param  string  $model  實際回答的模型名稱
     * @param  bool|null  $inputTruncated  輸入是否被供應商靜默截斷；null 代表無法判斷或不會發生（雲端超過上限會直接回錯誤）
     */
    public function __construct(
        public string $content,
        public Usage $usage,
        public string $model,
        public FinishReason $finishReason,
        public ?bool $inputTruncated = null,
    ) {}
}
