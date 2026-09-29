<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 層只依賴 ChatService：以 Http::fake() 模擬兩家上游，驗證同一段請求走同一條程式路徑、得到同樣格式的回應。
 */
class ChatApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('llm.ollama.base_url', 'http://ollama.test');
        config()->set('llm.openai.base_url', 'https://openai.test/v1');
        config()->set('llm.openai.api_key', 'sk-test');
    }

    /** @return array<string, mixed> */
    private function threeTurns(?string $provider = null): array
    {
        return array_filter([
            'provider' => $provider,
            'messages' => [
                ['role' => 'system', 'content' => '請使用繁體中文回答'],
                ['role' => 'user', 'content' => '請簡單介紹 RAG'],
                ['role' => 'assistant', 'content' => 'RAG 是先檢索再生成的做法'],
                ['role' => 'user', 'content' => '那它跟微調有什麼不同？'],
            ],
        ]);
    }

    private function fakeUpstreams(): void
    {
        Http::fake([
            'ollama.test/api/chat' => Http::response([
                'model' => 'qwen3:8b',
                'message' => ['role' => 'assistant', 'content' => 'Ollama 的回答'],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 40,
                'eval_count' => 10,
            ]),
            'openai.test/v1/responses' => Http::response([
                'status' => 'completed',
                'model' => 'gpt-4.1-mini',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'OpenAI 的回答']]]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 12],
            ]),
        ]);
    }

    /** @return array<string, array{string, string, int}> */
    public static function providers(): array
    {
        return [
            'ollama' => ['ollama', 'Ollama 的回答', 40],
            'openai' => ['openai', 'OpenAI 的回答', 50],
        ];
    }

    #[DataProvider('providers')]
    public function test_same_request_returns_same_response_shape_for_each_provider(string $provider, string $answer, int $inputTokens): void
    {
        $this->fakeUpstreams();

        $response = $this->postJson('/api/ai/chat', $this->threeTurns($provider));

        $response->assertOk()
            ->assertExactJsonStructure(['answer', 'usage' => ['input_tokens', 'output_tokens'], 'model', 'finish_reason'])
            ->assertJson(['answer' => $answer, 'usage' => ['input_tokens' => $inputTokens], 'finish_reason' => 'stop']);
    }

    public function test_upstream_receives_the_full_three_turn_conversation(): void
    {
        $this->fakeUpstreams();

        $this->postJson('/api/ai/chat', $this->threeTurns('openai'));

        Http::assertSent(fn ($request) => count($request['input']) === 3 && $request['input'][1]['role'] === 'assistant');
    }

    public function test_default_provider_is_used_when_provider_is_omitted(): void
    {
        config()->set('llm.chat.default', 'ollama');
        $this->fakeUpstreams();

        $this->postJson('/api/ai/chat', $this->threeTurns())->assertJson(['answer' => 'Ollama 的回答']);
    }

    public function test_chat_options_reach_the_provider(): void
    {
        $this->fakeUpstreams();

        $this->postJson('/api/ai/chat', [...$this->threeTurns('openai'), 'options' => ['model' => 'gpt-5.4-nano', 'max_tokens' => 50, 'temperature' => 0.3]]);

        Http::assertSent(fn ($request) => [$request['model'], $request['max_output_tokens'], $request['temperature']] === ['gpt-5.4-nano', 50, 0.3]);
    }

    // ---- 驗證失敗 ----

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): array
    {
        return [
            'empty messages' => [['messages' => []], 'messages'],
            'missing messages' => [[], 'messages'],
            'unknown role' => [['messages' => [['role' => 'model', 'content' => 'hi']]], 'messages.0.role'],
            'missing content' => [['messages' => [['role' => 'user']]], 'messages.0.content'],
            'unknown provider' => [['provider' => 'gemini', 'messages' => [['role' => 'user', 'content' => 'hi']]], 'provider'],
            'temperature out of range' => [['messages' => [['role' => 'user', 'content' => 'hi']], 'options' => ['temperature' => 5]], 'options.temperature'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_returns_422(array $payload, string $field): void
    {
        $this->postJson('/api/ai/chat', $payload)->assertStatus(422)->assertJsonValidationErrors($field);
    }

    public function test_conversation_rule_violation_returns_422(): void
    {
        $this->postJson('/api/ai/chat', ['messages' => [
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello'],
        ]])->assertStatus(422)->assertJsonPath('error.type', 'invalid_messages');
    }

    // ---- 上游錯誤 ----

    /** @return array<string, array{int, int, string}> */
    public static function upstreamErrors(): array
    {
        return [
            '429' => [429, 429, 'rate_limited'],
            '401' => [401, 502, 'provider_rejected'],
            '500' => [500, 502, 'provider_error'],
        ];
    }

    #[DataProvider('upstreamErrors')]
    public function test_upstream_errors_map_to_http_status(int $upstream, int $status, string $type): void
    {
        Http::fake(['openai.test/*' => Http::response(['error' => ['message' => 'upstream failed']], $upstream)]);

        $this->postJson('/api/ai/chat', $this->threeTurns('openai'))
            ->assertStatus($status)
            ->assertJsonPath('error.type', $type);
    }

    public function test_error_response_does_not_leak_api_key(): void
    {
        Http::fake(['openai.test/*' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-proj-LEAKED_SECRET_123.']], 401)]);

        $response = $this->postJson('/api/ai/chat', $this->threeTurns('openai'));

        $this->assertStringNotContainsString('LEAKED_SECRET', $response->getContent());
    }

    // ---- 串流 ----

    private function fakeOllamaStream(string ...$extraLines): void
    {
        $lines = [
            ['model' => 'qwen3:8b', 'message' => ['content' => '兩者'], 'done' => false],
            ['model' => 'qwen3:8b', 'message' => ['content' => '不同'], 'done' => false],
            ...array_map(fn ($line) => json_decode($line, true), $extraLines),
        ];

        Http::fake(['ollama.test/api/chat' => Http::response(
            implode("\n", array_map(fn ($line) => json_encode($line, JSON_UNESCAPED_UNICODE), $lines))."\n",
        )]);
    }

    public function test_stream_sends_delta_events_then_done_event(): void
    {
        $this->fakeOllamaStream('{"model":"qwen3:8b","message":{"content":""},"done":true,"done_reason":"stop","prompt_eval_count":40,"eval_count":2}');

        $body = $this->post('/api/ai/chat/stream', $this->threeTurns('ollama'))->assertOk()->streamedContent();

        $this->assertSame(
            "event: delta\ndata: {\"delta\":\"兩者\"}\n\n"
            ."event: delta\ndata: {\"delta\":\"不同\"}\n\n"
            ."event: done\ndata: {\"delta\":\"\",\"done\":true,\"usage\":{\"input_tokens\":40,\"output_tokens\":2},\"model\":\"qwen3:8b\",\"finish_reason\":\"stop\"}\n\n",
            $body,
        );
    }

    public function test_stream_error_after_start_is_sent_as_error_event(): void
    {
        $this->fakeOllamaStream('{"error":"model runner has unexpectedly stopped"}');

        $body = $this->post('/api/ai/chat/stream', $this->threeTurns('ollama'))->assertOk()->streamedContent();

        $this->assertStringEndsWith(
            "event: error\ndata: {\"error\":{\"type\":\"provider_error\",\"message\":\"[ollama] Stream error: model runner has unexpectedly stopped\"}}\n\n",
            $body,
        );
    }

    public function test_stream_error_before_first_chunk_returns_http_status(): void
    {
        Http::fake(['ollama.test/api/chat' => Http::response(['error' => 'busy'], 429)]);

        $this->postJson('/api/ai/chat/stream', $this->threeTurns('ollama'))
            ->assertStatus(429)
            ->assertJsonPath('error.type', 'rate_limited');
    }

    public function test_stream_validation_failure_returns_422(): void
    {
        $this->postJson('/api/ai/chat/stream', ['messages' => []])->assertStatus(422);
    }
}
