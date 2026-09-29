<?php

namespace App\Http;

use App\Ai\Chat\Exceptions\InvalidMessagesException;
use App\Ai\Chat\Exceptions\UnknownChatProviderException;
use App\Ai\Exceptions\LlmClientException;
use App\Ai\Exceptions\LlmConnectionException;
use App\Ai\Exceptions\LlmRateLimitException;
use App\Ai\Exceptions\LlmResponseFormatException;
use App\Ai\Exceptions\LlmServerException;
use App\Ai\Exceptions\LlmTimeoutException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * AI 例外 → HTTP 狀態碼與錯誤類型的唯一對照表，一般 JSON 回應與 SSE 錯誤事件共用。
 * 原則：呼叫端的錯回 4xx；上游或設定的錯回 5xx（不是呼叫端能修正的）。
 */
final class AiErrors
{
    /** @var array<class-string<Throwable>, array{int, string}> 例外 → [HTTP 狀態碼, 錯誤類型] */
    private const MAP = [
        InvalidMessagesException::class => [422, 'invalid_messages'],
        UnknownChatProviderException::class => [422, 'unknown_provider'],
        LlmRateLimitException::class => [429, 'rate_limited'],
        LlmConnectionException::class => [503, 'provider_unavailable'],
        LlmTimeoutException::class => [504, 'provider_timeout'],
        LlmClientException::class => [502, 'provider_rejected'],
        LlmServerException::class => [502, 'provider_error'],
        LlmResponseFormatException::class => [502, 'invalid_provider_response'],
    ];

    /** @return array{int, string}|null 不屬於 AI 例外時回傳 null，交給 Laravel 預設處理 */
    public static function classify(Throwable $e): ?array
    {
        return self::MAP[$e::class] ?? null;
    }

    /** @return array{type: string, message: string} */
    public static function body(Throwable $e): array
    {
        return ['type' => self::classify($e)[1] ?? 'internal_error', 'message' => $e->getMessage()];
    }

    public static function render(Throwable $e): ?JsonResponse
    {
        $classified = self::classify($e);

        return $classified === null ? null : new JsonResponse(['error' => self::body($e)], $classified[0], options: JSON_UNESCAPED_UNICODE);
    }
}
