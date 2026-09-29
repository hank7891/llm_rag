<?php

namespace App\Ai\Support;

use App\Ai\Exceptions\LlmClientException;
use App\Ai\Exceptions\LlmConnectionException;
use App\Ai\Exceptions\LlmException;
use App\Ai\Exceptions\LlmRateLimitException;
use App\Ai\Exceptions\LlmResponseFormatException;
use App\Ai\Exceptions\LlmServerException;
use App\Ai\Exceptions\LlmTimeoutException;
use Generator;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Exception\TimeoutException as StreamTimeoutException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * 各 Provider 共用的 HTTP 呼叫：送出請求，並把連線失敗、逾時、HTTP 錯誤轉成專案自己的例外。
 * 例外訊息只含 provider 名稱、狀態碼與上游錯誤訊息，不含 request header（API Key 在 header 中）。
 */
final class LlmHttp
{
    /** @return array<mixed> */
    public static function postJson(string $provider, PendingRequest $request, string $url, array $body): array
    {
        $json = self::send($provider, fn () => $request->post($url, $body))->json();

        if (! is_array($json)) {
            throw new LlmResponseFormatException("[{$provider}] Response body is not a JSON object.");
        }

        return $json;
    }

    /**
     * 逐行讀取串流回應（NDJSON 或 SSE），略過空行。
     * Guzzle 的 timeout 管不到串流 body 的讀取時間（實測），因此由這裡自行檢查總期限。
     *
     * @return Generator<int, string>
     */
    public static function streamLines(string $provider, PendingRequest $request, string $url, array $body, int $deadlineSeconds): Generator
    {
        $expiresAt = hrtime(true) + $deadlineSeconds * 1_000_000_000;
        $stream = self::send($provider, fn () => $request->withOptions(['stream' => true])->post($url, $body))
            ->toPsrResponse()
            ->getBody();

        while (! $stream->eof()) {
            if (hrtime(true) > $expiresAt) {
                throw new LlmTimeoutException("[{$provider}] Stream exceeded the {$deadlineSeconds}s deadline.");
            }

            // 逐行讀取：read(n) 會等湊滿 n bytes 才回傳，文字會整批延遲送達（實測）
            try {
                $line = trim(Utils::readLine($stream));
            } catch (StreamTimeoutException $e) {
                throw new LlmTimeoutException("[{$provider}] Stream read timed out.", previous: $e);
            }

            if ($line !== '') {
                yield $line;
            }
        }
    }

    /**
     * 遮蔽上游錯誤訊息中的 API Key。實測 OpenAI 401 會把送出的 Key 原樣寫進錯誤訊息。
     * sk- 開頭涵蓋 OpenAI（sk-proj-…）與 Anthropic（sk-ant-…）。
     */
    public static function redact(string $text): string
    {
        return preg_replace('/\bsk-[A-Za-z0-9_\-*]+/', 'sk-[REDACTED]', $text) ?? '[REDACTED]';
    }

    /** @param callable(): Response $send */
    private static function send(string $provider, callable $send): Response
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw $e->getPrevious() instanceof NetworkTimeoutException
                ? new LlmTimeoutException("[{$provider}] Request timed out.", previous: $e)
                : new LlmConnectionException("[{$provider}] Could not connect to the provider.", previous: $e);
        }

        if ($response->failed()) {
            throw self::httpError($provider, $response);
        }

        return $response;
    }

    private static function httpError(string $provider, Response $response): LlmException
    {
        $status = $response->status();
        $json = $response->json();

        // Ollama：{"error": "..."}；OpenAI / Anthropic：{"error": {"message": "..."}}
        $detail = match (true) {
            is_string($json['error'] ?? null) => $json['error'],
            is_string($json['error']['message'] ?? null) => $json['error']['message'],
            default => mb_substr($response->body(), 0, 200),
        };
        $message = "[{$provider}] HTTP {$status}: ".self::redact($detail);

        return match (true) {
            $status === 429 => new LlmRateLimitException($message),
            $status >= 500 => new LlmServerException($message),
            default => new LlmClientException($message),
        };
    }
}
