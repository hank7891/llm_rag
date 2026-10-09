<?php

namespace App\Rag\Conversation;

use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Usage;

/**
 * 改寫結果。question 是檢索要用的問題：改寫成功時為改寫結果，否則為原問題。
 * called：是否呼叫了改寫 LLM（skipped、沒有改寫設定時為 false；fallback 可能是呼叫後失敗，也可能是逾時）。
 * provider：改寫使用（或嘗試使用）的 Provider；skipped 時為 null。
 */
final readonly class RewriteResult
{
    public function __construct(
        public string $question,
        public RewriteStatus $status,
        public bool $called,
        public ?int $latencyMs = null,
        public ?Usage $usage = null,
        public ?string $raw = null,
        public ?string $failureReason = null,
        public ?FinishReason $finishReason = null,
        public ?string $provider = null,
    ) {}
}
