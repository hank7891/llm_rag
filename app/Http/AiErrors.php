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
use App\Documents\Exceptions\DocumentNotReadyException;
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
        DocumentNotReadyException::class => [409, 'document_not_ready'],
        LlmRateLimitException::class => [429, 'rate_limited'],
        LlmConnectionException::class => [503, 'provider_unavailable'],
        LlmTimeoutException::class => [504, 'provider_timeout'],
        LlmClientException::class => [502, 'provider_rejected'],
        LlmServerException::class => [502, 'provider_error'],
        LlmResponseFormatException::class => [502, 'invalid_provider_response'],
    ];

    /** @var array<string, string> 錯誤類型 → 給使用者看的訊息（串流的 error 事件用，不含上游原始訊息與內部位址） */
    private const USER_MESSAGES = [
        'rate_limited' => '模型服務目前請求過多，請稍後再試。',
        'provider_unavailable' => '連不上模型服務，請確認服務是否啟動。',
        'provider_timeout' => '模型回應逾時，請稍後再試。',
        'provider_rejected' => '模型服務拒絕了這次請求。',
        'provider_error' => '模型服務發生錯誤，請稍後再試。',
        'invalid_provider_response' => '模型服務的回應格式不正確。',
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

    /**
     * 給終端使用者的錯誤：只有錯誤類型與固定訊息。完整的例外訊息只寫進 log。
     *
     * @return array{type: string, message: string}
     */
    public static function publicBody(Throwable $e): array
    {
        $type = self::classify($e)[1] ?? 'internal_error';

        return ['type' => $type, 'message' => self::USER_MESSAGES[$type] ?? '系統發生錯誤，請稍後再試。'];
    }

    public static function render(Throwable $e): ?JsonResponse
    {
        $classified = self::classify($e);

        return $classified === null ? null : new JsonResponse(['error' => self::body($e)], $classified[0], options: JSON_UNESCAPED_UNICODE);
    }
}
