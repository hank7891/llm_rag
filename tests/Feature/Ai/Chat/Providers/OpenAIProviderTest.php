<?php

namespace Tests\Feature\Ai\Chat\Providers;

use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Chat\Providers\OpenAIProvider;
use App\Ai\Exceptions\LlmClientException;
use App\Ai\Exceptions\LlmRateLimitException;
use App\Ai\Exceptions\LlmResponseFormatException;
use App\Ai\Exceptions\LlmServerException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAIProviderTest extends TestCase
{
    private const URL = 'https://openai.test/v1/responses';

    private const API_KEY = 'sk-proj-TEST_SECRET_KEY_0123456789';

    /** @param array<string, mixed> $override */
    private function provider(array $override = []): OpenAIProvider
    {
        return new OpenAIProvider(array_merge([
            'base_url' => 'https://openai.test/v1',
            'api_key' => self::API_KEY,
            'model' => 'gpt-4.1-mini',
            'default_max_tokens' => 1024,
            'connect_timeout' => 5,
            'timeout' => 60,
            'stream_timeout' => 300,
        ], $override));
    }

    /** @return list<Message> */
    private function messages(): array
    {
        return [
            new Message(Role::System, '請使用繁體中文回答'),
            new Message(Role::System, '回答請簡短'),
            new Message(Role::User, '請簡單介紹 RAG'),
            new Message(Role::Assistant, 'RAG 是先檢索再生成的做法'),
            new Message(Role::User, '那它跟微調有什麼不同？'),
        ];
    }

    /**
     * 依實測回應精簡（保留不認識的欄位，驗證 Tolerant Reader 會忽略它們）。
     *
     * @param  array<string, mixed>  $override
     */
    private function response(array $override = [], ?array $content = null): array
    {
        return array_merge([
            'id' => 'resp_1',
            'object' => 'response',
            'status' => 'completed',
            'incomplete_details' => null,
            'model' => 'gpt-4.1-mini-2025-04-14',
            'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => $content ?? [
                        ['type' => 'output_text', 'annotations' => [], 'text' => '兩者差在'],
                        ['type' => 'output_text', 'annotations' => [], 'text' => '知識來源。'],
                    ],
                ],
            ],
            'usage' => ['input_tokens' => 65, 'output_tokens' => 26, 'output_tokens_details' => ['reasoning_tokens' => 0]],
            'tool_usage' => ['web_search' => ['num_requests' => 0]],
        ], $override);
    }

    /** @param list<array<string, mixed>> $events */
    private function sse(array $events): string
    {
        return implode('', array_map(
            fn ($event) => 'event: '.$event['type']."\ndata: ".json_encode($event, JSON_UNESCAPED_UNICODE)."\n\n",
            $events,
        ));
    }

    /** @return list<array<string, mixed>> */
    private function streamEvents(): array
    {
        return [
            ['type' => 'response.created', 'response' => ['status' => 'in_progress'], 'sequence_number' => 0],
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message'], 'sequence_number' => 1],
            ['type' => 'response.output_text.delta', 'delta' => '兩者差在', 'sequence_number' => 2],
            ['type' => 'response.output_text.delta', 'delta' => '知識來源。', 'sequence_number' => 3],
            ['type' => 'response.output_text.done', 'text' => '兩者差在知識來源。', 'sequence_number' => 4],
            ['type' => 'response.completed', 'response' => $this->response(), 'sequence_number' => 5],
        ];
    }

    /** @return list<StreamChunk> */
    private function collect(iterable $chunks): array
    {
        return iterator_to_array($chunks, false);
    }

    // ---- request body ----

    public function test_leading_system_messages_become_instructions(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $this->provider()->chat($this->messages(), new ChatOptions);

        Http::assertSent(fn (Request $request) => $request['instructions'] === "請使用繁體中文回答\n\n回答請簡短");
    }

    public function test_input_contains_no_system_messages(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $this->provider()->chat($this->messages(), new ChatOptions);

        Http::assertSent(fn (Request $request) => $request['input'] === [
            ['role' => 'user', 'content' => '請簡單介紹 RAG'],
            ['role' => 'assistant', 'content' => 'RAG 是先檢索再生成的做法'],
            ['role' => 'user', 'content' => '那它跟微調有什麼不同？'],
        ]);
    }

    public function test_instructions_are_omitted_without_system_messages(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $this->provider()->chat(array_slice($this->messages(), 2), new ChatOptions);

        Http::assertSent(fn (Request $request) => ! array_key_exists('instructions', $request->data()));
    }

    public function test_default_max_tokens_applies_when_option_is_null(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $this->provider()->chat($this->messages(), new ChatOptions);

        Http::assertSent(fn (Request $request) => $request['max_output_tokens'] === 1024);
    }

    public function test_chat_options_override_model_max_tokens_and_temperature(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $this->provider()->chat($this->messages(), new ChatOptions(model: 'gpt-5.4-nano', temperature: 0.2, maxTokens: 100));

        Http::assertSent(fn (Request $request) => [$request['model'], $request['temperature'], $request['max_output_tokens']]
            === ['gpt-5.4-nano', 0.2, 100]);
    }

    public function test_temperature_is_omitted_when_null(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $this->provider()->chat($this->messages(), new ChatOptions);

        Http::assertSent(fn (Request $request) => ! array_key_exists('temperature', $request->data()));
    }

    public function test_request_disables_server_side_storage(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $this->provider()->chat($this->messages(), new ChatOptions);

        Http::assertSent(fn (Request $request) => $request['store'] === false);
    }

    public function test_api_key_is_sent_as_bearer_token(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $this->provider()->chat($this->messages(), new ChatOptions);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.self::API_KEY));
    }

    // ---- 回應轉換 ----

    public function test_output_text_parts_join_into_chat_result(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $result = $this->provider()->chat($this->messages(), new ChatOptions);

        $this->assertSame(
            ['兩者差在知識來源。', 65, 26, 'gpt-4.1-mini-2025-04-14', FinishReason::Stop],
            [$result->content, $result->usage->inputTokens, $result->usage->outputTokens, $result->model, $result->finishReason],
        );
    }

    /** @return array<string, array{array<string, mixed>, FinishReason}> */
    public static function statuses(): array
    {
        return [
            'completed' => [['status' => 'completed'], FinishReason::Stop],
            'max_output_tokens' => [['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']], FinishReason::Length],
            'content_filter' => [['status' => 'incomplete', 'incomplete_details' => ['reason' => 'content_filter']], FinishReason::ContentFilter],
            'unknown incomplete reason' => [['status' => 'incomplete', 'incomplete_details' => ['reason' => 'something_new']], FinishReason::Other],
        ];
    }

    #[DataProvider('statuses')]
    public function test_status_maps_to_finish_reason(array $override, FinishReason $expected): void
    {
        Http::fake([self::URL => Http::response($this->response($override))]);

        $result = $this->provider()->chat($this->messages(), new ChatOptions);

        $this->assertSame($expected, $result->finishReason);
    }

    public function test_refusal_maps_to_content_filter(): void
    {
        Http::fake([self::URL => Http::response($this->response(content: [['type' => 'refusal', 'refusal' => '無法協助']]))]);

        $result = $this->provider()->chat($this->messages(), new ChatOptions);

        $this->assertSame(['', FinishReason::ContentFilter], [$result->content, $result->finishReason]);
    }

    public function test_missing_status_is_rejected(): void
    {
        $response = $this->response();
        unset($response['status']);
        Http::fake([self::URL => Http::response($response)]);

        $this->expectException(LlmResponseFormatException::class);
        $this->expectExceptionMessage('[status]');

        $this->provider()->chat($this->messages(), new ChatOptions);
    }

    public function test_missing_output_is_rejected(): void
    {
        $response = $this->response();
        unset($response['output']);
        Http::fake([self::URL => Http::response($response)]);

        $this->expectException(LlmResponseFormatException::class);
        $this->expectExceptionMessage('[output]');

        $this->provider()->chat($this->messages(), new ChatOptions);
    }

    public function test_completed_without_answer_text_is_rejected(): void
    {
        Http::fake([self::URL => Http::response($this->response(content: []))]);

        $this->expectException(LlmResponseFormatException::class);

        $this->provider()->chat($this->messages(), new ChatOptions);
    }

    // ---- 串流 ----

    public function test_stream_yields_text_deltas_and_ignores_other_events(): void
    {
        Http::fake([self::URL => Http::response($this->sse($this->streamEvents()))]);

        $chunks = $this->collect($this->provider()->stream($this->messages(), new ChatOptions));

        $this->assertSame(['兩者差在', '知識來源。', ''], array_map(fn (StreamChunk $c) => $c->delta, $chunks));
    }

    public function test_last_stream_chunk_carries_usage_finish_reason_and_model(): void
    {
        Http::fake([self::URL => Http::response($this->sse($this->streamEvents()))]);

        $chunks = $this->collect($this->provider()->stream($this->messages(), new ChatOptions));
        $last = end($chunks);

        $this->assertSame(
            [65, 26, FinishReason::Stop, 'gpt-4.1-mini-2025-04-14'],
            [$last->usage?->inputTokens, $last->usage?->outputTokens, $last->finishReason, $last->model],
        );
    }

    public function test_incomplete_stream_ends_with_length(): void
    {
        $events = $this->streamEvents();
        $events[5] = ['type' => 'response.incomplete', 'response' => $this->response([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
        ])];
        Http::fake([self::URL => Http::response($this->sse($events))]);

        $chunks = $this->collect($this->provider()->stream($this->messages(), new ChatOptions));

        $this->assertSame(FinishReason::Length, end($chunks)->finishReason);
    }

    public function test_stream_ending_without_completed_event_is_rejected(): void
    {
        Http::fake([self::URL => Http::response($this->sse(array_slice($this->streamEvents(), 0, 4)))]);

        $this->expectException(LlmResponseFormatException::class);
        $this->expectExceptionMessage('Stream ended before the completed event');

        $this->collect($this->provider()->stream($this->messages(), new ChatOptions));
    }

    public function test_failed_stream_event_is_rejected(): void
    {
        $events = array_slice($this->streamEvents(), 0, 3);
        $events[] = ['type' => 'response.failed', 'response' => ['status' => 'failed', 'error' => ['message' => 'The server had an error']]];
        Http::fake([self::URL => Http::response($this->sse($events))]);

        $this->expectException(LlmServerException::class);
        $this->expectExceptionMessage('The server had an error');

        $this->collect($this->provider()->stream($this->messages(), new ChatOptions));
    }

    // ---- 錯誤分類與 API Key 遮蔽 ----

    /** @return array<string, array{int, class-string}> */
    public static function httpErrors(): array
    {
        return [
            '401 invalid key' => [401, LlmClientException::class],
            '429 rate limited' => [429, LlmRateLimitException::class],
            '503 overloaded' => [503, LlmServerException::class],
        ];
    }

    #[DataProvider('httpErrors')]
    public function test_http_errors_become_project_exceptions(int $status, string $exception): void
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'upstream error', 'type' => 'x']], $status)]);

        $this->expectException($exception);
        $this->expectExceptionMessage("[openai] HTTP {$status}: upstream error");

        $this->provider()->chat($this->messages(), new ChatOptions);
    }

    public function test_api_key_echoed_by_upstream_is_redacted(): void
    {
        // 實測 OpenAI 401 會把送出的 Key 寫進錯誤訊息
        Http::fake([self::URL => Http::response(['error' => [
            'message' => 'Incorrect API key provided: '.self::API_KEY.'. You can find your API key at https://platform.openai.com/account/api-keys.',
        ]], 401)]);

        try {
            $this->provider()->chat($this->messages(), new ChatOptions);
            $this->fail('Expected LlmClientException.');
        } catch (LlmClientException $e) {
            $this->assertStringNotContainsString('TEST_SECRET_KEY', $e->getMessage());
        }
    }

    public function test_missing_api_key_fails_before_sending(): void
    {
        Http::fake();

        $this->expectExceptionMessage('No API key configured (OPENAI_API_KEY)');

        $this->provider(['api_key' => null])->chat($this->messages(), new ChatOptions);
    }
}
