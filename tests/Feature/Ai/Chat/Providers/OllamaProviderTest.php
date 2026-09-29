<?php

namespace Tests\Feature\Ai\Chat\Providers;

use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Chat\Providers\OllamaProvider;
use App\Ai\Exceptions\LlmClientException;
use App\Ai\Exceptions\LlmConnectionException;
use App\Ai\Exceptions\LlmRateLimitException;
use App\Ai\Exceptions\LlmResponseFormatException;
use App\Ai\Exceptions\LlmServerException;
use App\Ai\Exceptions\LlmTimeoutException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OllamaProviderTest extends TestCase
{
    private const URL = 'http://ollama.test/api/chat';

    /** @param array<string, mixed> $override */
    private function provider(array $override = []): OllamaProvider
    {
        return new OllamaProvider(array_merge([
            'base_url' => 'http://ollama.test',
            'model' => 'qwen3:8b',
            'num_ctx' => 8192,
            'think' => false,
            'connect_timeout' => 5,
            'timeout' => 120,
            'stream_timeout' => 300,
        ], $override));
    }

    /** @return list<Message> */
    private function messages(): array
    {
        return [
            new Message(Role::System, '請使用繁體中文回答'),
            new Message(Role::User, '請簡單介紹 RAG'),
            new Message(Role::Assistant, 'RAG 是先檢索再生成的做法'),
            new Message(Role::User, '那它跟微調有什麼不同？'),
        ];
    }

    /** @param array<string, mixed> $override */
    private function chatResponse(array $override = []): array
    {
        return array_merge([
            'model' => 'qwen3:8b',
            'created_at' => '2026-09-29T00:00:00Z',
            'message' => ['role' => 'assistant', 'content' => '兩者差在知識來源。'],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 42,
            'eval_count' => 37,
            'total_duration' => 123,
        ], $override);
    }

    /** @param list<array<string, mixed>> $events */
    private function ndjson(array $events): string
    {
        return implode("\n", array_map(fn ($event) => json_encode($event, JSON_UNESCAPED_UNICODE), $events))."\n";
    }

    /** @return list<array<string, mixed>> */
    private function streamEvents(): array
    {
        return [
            ['model' => 'qwen3:8b', 'message' => ['role' => 'assistant', 'content' => '兩者'], 'done' => false],
            ['model' => 'qwen3:8b', 'message' => ['role' => 'assistant', 'content' => '差在'], 'done' => false],
            ['model' => 'qwen3:8b', 'message' => ['role' => 'assistant', 'content' => '知識來源。'], 'done' => false],
            ['model' => 'qwen3:8b', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 42, 'eval_count' => 3],
        ];
    }

    /** @return list<StreamChunk> */
    private function collect(iterable $chunks): array
    {
        return iterator_to_array($chunks, false);
    }

    // ---- request body ----

    public function test_request_body_keeps_messages_in_order_with_system_inline(): void
    {
        Http::fake([self::URL => Http::response($this->chatResponse())]);

        $this->provider()->chat($this->messages(), new ChatOptions);

        Http::assertSent(fn (Request $request) => $request['messages'] === [
            ['role' => 'system', 'content' => '請使用繁體中文回答'],
            ['role' => 'user', 'content' => '請簡單介紹 RAG'],
            ['role' => 'assistant', 'content' => 'RAG 是先檢索再生成的做法'],
            ['role' => 'user', 'content' => '那它跟微調有什麼不同？'],
        ]);
    }

    public function test_request_body_carries_provider_specific_settings(): void
    {
        Http::fake([self::URL => Http::response($this->chatResponse())]);

        $this->provider()->chat($this->messages(), new ChatOptions);

        Http::assertSent(fn (Request $request) => [$request['model'], $request['stream'], $request['think'], $request['options']]
            === ['qwen3:8b', false, false, ['num_ctx' => 8192]]);
    }

    public function test_think_can_be_enabled_by_config(): void
    {
        Http::fake([self::URL => Http::response($this->chatResponse())]);

        $this->provider(['think' => true])->chat($this->messages(), new ChatOptions);

        Http::assertSent(fn (Request $request) => $request['think'] === true);
    }

    public function test_chat_options_map_to_ollama_names(): void
    {
        Http::fake([self::URL => Http::response($this->chatResponse())]);

        $this->provider()->chat($this->messages(), new ChatOptions(model: 'llama3', temperature: 0.2, maxTokens: 100));

        Http::assertSent(fn (Request $request) => [$request['model'], $request['options']]
            === ['llama3', ['num_ctx' => 8192, 'temperature' => 0.2, 'num_predict' => 100]]);
    }

    // ---- 回應轉換 ----

    public function test_chat_response_becomes_chat_result(): void
    {
        Http::fake([self::URL => Http::response($this->chatResponse())]);

        $result = $this->provider()->chat($this->messages(), new ChatOptions);

        $this->assertSame(
            ['兩者差在知識來源。', 42, 37, 'qwen3:8b', FinishReason::Stop],
            [$result->content, $result->usage->inputTokens, $result->usage->outputTokens, $result->model, $result->finishReason],
        );
    }

    /** @return array<string, array{mixed, FinishReason}> */
    public static function doneReasons(): array
    {
        return [
            'stop' => ['stop', FinishReason::Stop],
            'length' => ['length', FinishReason::Length],
            'unknown' => ['unload', FinishReason::Other],
            'missing' => [null, FinishReason::Other],
        ];
    }

    #[DataProvider('doneReasons')]
    public function test_done_reason_maps_to_finish_reason(mixed $doneReason, FinishReason $expected): void
    {
        Http::fake([self::URL => Http::response($this->chatResponse(['done_reason' => $doneReason]))]);

        $result = $this->provider()->chat($this->messages(), new ChatOptions);

        $this->assertSame($expected, $result->finishReason);
    }

    public function test_missing_usage_defaults_to_zero(): void
    {
        $response = $this->chatResponse();
        unset($response['prompt_eval_count'], $response['eval_count']);
        Http::fake([self::URL => Http::response($response)]);

        $result = $this->provider()->chat($this->messages(), new ChatOptions);

        $this->assertSame([0, 0], [$result->usage->inputTokens, $result->usage->outputTokens]);
    }

    public function test_missing_content_is_rejected(): void
    {
        Http::fake([self::URL => Http::response($this->chatResponse(['message' => ['role' => 'assistant']]))]);

        $this->expectException(LlmResponseFormatException::class);
        $this->expectExceptionMessage('[message.content]');

        $this->provider()->chat($this->messages(), new ChatOptions);
    }

    public function test_empty_answer_with_normal_stop_is_rejected(): void
    {
        Http::fake([self::URL => Http::response($this->chatResponse(['message' => ['content' => '']]))]);

        $this->expectException(LlmResponseFormatException::class);

        $this->provider()->chat($this->messages(), new ChatOptions);
    }

    public function test_empty_answer_cut_by_length_is_allowed(): void
    {
        Http::fake([self::URL => Http::response($this->chatResponse(['message' => ['content' => ''], 'done_reason' => 'length']))]);

        $result = $this->provider()->chat($this->messages(), new ChatOptions);

        $this->assertSame(['', FinishReason::Length], [$result->content, $result->finishReason]);
    }

    public function test_thinking_is_not_mixed_into_content(): void
    {
        Http::fake([self::URL => Http::response($this->chatResponse(['message' => ['content' => '答案', 'thinking' => '思考過程']]))]);

        $result = $this->provider()->chat($this->messages(), new ChatOptions);

        $this->assertSame('答案', $result->content);
    }

    // ---- 串流 ----

    public function test_stream_request_sets_stream_true(): void
    {
        Http::fake([self::URL => Http::response($this->ndjson($this->streamEvents()))]);

        $this->collect($this->provider()->stream($this->messages(), new ChatOptions));

        Http::assertSent(fn (Request $request) => $request['stream'] === true);
    }

    public function test_stream_yields_deltas_in_order(): void
    {
        Http::fake([self::URL => Http::response($this->ndjson($this->streamEvents()))]);

        $chunks = $this->collect($this->provider()->stream($this->messages(), new ChatOptions));

        $this->assertSame(['兩者', '差在', '知識來源。', ''], array_map(fn (StreamChunk $c) => $c->delta, $chunks));
    }

    public function test_last_stream_chunk_carries_usage_finish_reason_and_model(): void
    {
        Http::fake([self::URL => Http::response($this->ndjson($this->streamEvents()))]);

        $chunks = $this->collect($this->provider()->stream($this->messages(), new ChatOptions));
        $last = end($chunks);

        $this->assertSame(
            [42, 3, FinishReason::Stop, 'qwen3:8b'],
            [$last->usage?->inputTokens, $last->usage?->outputTokens, $last->finishReason, $last->model],
        );
    }

    public function test_earlier_stream_chunks_carry_no_final_fields(): void
    {
        Http::fake([self::URL => Http::response($this->ndjson($this->streamEvents()))]);

        $chunks = $this->collect($this->provider()->stream($this->messages(), new ChatOptions));
        array_pop($chunks);

        $this->assertSame(
            [[null, null, null], [null, null, null], [null, null, null]],
            array_map(fn (StreamChunk $c) => [$c->usage, $c->finishReason, $c->model], $chunks),
        );
    }

    public function test_stream_skips_thinking_only_events(): void
    {
        Http::fake([self::URL => Http::response($this->ndjson([
            ['model' => 'qwen3:8b', 'message' => ['content' => '', 'thinking' => '先想一下'], 'done' => false],
            ...$this->streamEvents(),
        ]))]);

        $chunks = $this->collect($this->provider()->stream($this->messages(), new ChatOptions));

        $this->assertSame('兩者差在知識來源。', implode('', array_map(fn (StreamChunk $c) => $c->delta, $chunks)));
    }

    public function test_stream_ending_without_done_event_is_rejected(): void
    {
        Http::fake([self::URL => Http::response($this->ndjson(array_slice($this->streamEvents(), 0, 2)))]);

        $this->expectException(LlmResponseFormatException::class);
        $this->expectExceptionMessage('Stream ended before the done event');

        $this->collect($this->provider()->stream($this->messages(), new ChatOptions));
    }

    public function test_error_line_in_stream_is_rejected(): void
    {
        Http::fake([self::URL => Http::response($this->ndjson([
            $this->streamEvents()[0],
            ['error' => 'model runner has unexpectedly stopped'],
        ]))]);

        $this->expectException(LlmServerException::class);
        $this->expectExceptionMessage('model runner has unexpectedly stopped');

        $this->collect($this->provider()->stream($this->messages(), new ChatOptions));
    }

    // ---- 錯誤分類 ----

    /** @return array<string, array{int, class-string}> */
    public static function httpErrors(): array
    {
        return [
            '404 model not found' => [404, LlmClientException::class],
            '429 rate limited' => [429, LlmRateLimitException::class],
            '500 server error' => [500, LlmServerException::class],
        ];
    }

    #[DataProvider('httpErrors')]
    public function test_http_errors_become_project_exceptions(int $status, string $exception): void
    {
        Http::fake([self::URL => Http::response(['error' => "model 'nope' not found"], $status)]);

        $this->expectException($exception);
        $this->expectExceptionMessage("[ollama] HTTP {$status}: model 'nope' not found");

        $this->provider()->chat($this->messages(), new ChatOptions);
    }

    public function test_connection_refused_becomes_connection_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7', 0, new ConnectException('refused', new PsrRequest('POST', self::URL))));

        $this->expectException(LlmConnectionException::class);

        $this->provider()->chat($this->messages(), new ChatOptions);
    }

    public function test_network_timeout_becomes_timeout_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28', 0, new NetworkTimeoutException('timed out', new PsrRequest('POST', self::URL))));

        $this->expectException(LlmTimeoutException::class);

        $this->provider()->chat($this->messages(), new ChatOptions);
    }

    public function test_non_json_body_is_rejected(): void
    {
        Http::fake([self::URL => Http::response('<html>proxy error</html>')]);

        $this->expectException(LlmResponseFormatException::class);

        $this->provider()->chat($this->messages(), new ChatOptions);
    }
}
