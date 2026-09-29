<?php

namespace Tests\Feature\Ai\Chat\Providers;

use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Chat\Providers\GeminiProvider;
use App\Ai\Exceptions\LlmClientException;
use App\Ai\Exceptions\LlmResponseFormatException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 只測 Gemini 特有的轉換；錯誤分類、逾時等共用邏輯已在 Ollama / OpenAI 測試中涵蓋。
 */
class GeminiProviderTest extends TestCase
{
    private const URL = 'https://gemini.test/v1beta/models/gemini-3.5-flash-lite:generateContent';

    private const STREAM_URL = 'https://gemini.test/v1beta/models/gemini-3.5-flash-lite:streamGenerateContent?alt=sse';

    private function provider(): GeminiProvider
    {
        return new GeminiProvider([
            'base_url' => 'https://gemini.test/v1beta/models',
            'api_key' => 'AIza-test-key',
            'model' => 'gemini-3.5-flash-lite',
            'default_max_tokens' => 1024,
            'connect_timeout' => 5,
            'timeout' => 60,
            'stream_timeout' => 300,
        ]);
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

    /** 依實測回應精簡；thoughtSignature 等不認識的欄位保留，驗證會被忽略。 */
    private function response(string $finishReason = 'STOP', ?array $parts = null, array $usage = []): array
    {
        return [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => $parts ?? [['text' => '兩者差在知識來源。', 'thoughtSignature' => 'abc']]],
                'finishReason' => $finishReason,
                'index' => 0,
            ]],
            'usageMetadata' => $usage + ['promptTokenCount' => 42, 'candidatesTokenCount' => 27, 'totalTokenCount' => 69],
            'modelVersion' => 'gemini-3.5-flash-lite',
            'responseId' => 'r1',
        ];
    }

    public function test_request_uses_model_in_url_and_key_in_header(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $this->provider()->chat($this->messages(), new ChatOptions);

        Http::assertSent(fn (Request $request) => $request->url() === self::URL
            && $request->hasHeader('x-goog-api-key', 'AIza-test-key')
            && ! str_contains($request->url(), 'key='));
    }

    public function test_system_moves_to_system_instruction_and_assistant_becomes_model(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $this->provider()->chat($this->messages(), new ChatOptions(temperature: 0.5));

        Http::assertSent(fn (Request $request) => $request->data() === [
            'systemInstruction' => ['parts' => [['text' => '請使用繁體中文回答']]],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => '請簡單介紹 RAG']]],
                ['role' => 'model', 'parts' => [['text' => 'RAG 是先檢索再生成的做法']]],
                ['role' => 'user', 'parts' => [['text' => '那它跟微調有什麼不同？']]],
            ],
            'generationConfig' => ['maxOutputTokens' => 1024, 'temperature' => 0.5],
        ]);
    }

    public function test_response_becomes_chat_result(): void
    {
        Http::fake([self::URL => Http::response($this->response())]);

        $result = $this->provider()->chat($this->messages(), new ChatOptions);

        $this->assertSame(
            ['兩者差在知識來源。', 42, 27, 'gemini-3.5-flash-lite', FinishReason::Stop],
            [$result->content, $result->usage->inputTokens, $result->usage->outputTokens, $result->model, $result->finishReason],
        );
    }

    public function test_thought_parts_are_excluded_but_thinking_tokens_count_as_output(): void
    {
        Http::fake([self::URL => Http::response($this->response(
            parts: [['text' => '先想一下', 'thought' => true], ['text' => '答案']],
            usage: ['candidatesTokenCount' => 5, 'thoughtsTokenCount' => 30],
        ))]);

        $result = $this->provider()->chat($this->messages(), new ChatOptions);

        $this->assertSame(['答案', 35], [$result->content, $result->usage->outputTokens]);
    }

    /** @return array<string, array{string, FinishReason}> */
    public static function finishReasons(): array
    {
        return [
            'STOP' => ['STOP', FinishReason::Stop],
            'MAX_TOKENS' => ['MAX_TOKENS', FinishReason::Length],
            'SAFETY' => ['SAFETY', FinishReason::ContentFilter],
            'unknown' => ['SOMETHING_NEW', FinishReason::Other],
        ];
    }

    #[DataProvider('finishReasons')]
    public function test_finish_reason_maps_to_project_value(string $raw, FinishReason $expected): void
    {
        Http::fake([self::URL => Http::response($this->response($raw))]);

        $this->assertSame($expected, $this->provider()->chat($this->messages(), new ChatOptions)->finishReason);
    }

    public function test_blocked_prompt_maps_to_content_filter(): void
    {
        Http::fake([self::URL => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 9]])]);

        $result = $this->provider()->chat($this->messages(), new ChatOptions);

        $this->assertSame(['', FinishReason::ContentFilter], [$result->content, $result->finishReason]);
    }

    public function test_stop_without_text_is_rejected(): void
    {
        Http::fake([self::URL => Http::response($this->response(parts: []))]);

        $this->expectException(LlmResponseFormatException::class);

        $this->provider()->chat($this->messages(), new ChatOptions);
    }

    public function test_invalid_key_400_becomes_client_exception(): void
    {
        // 實測：Gemini 對無效 Key 回 400 而不是 401
        Http::fake([self::URL => Http::response(['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT']], 400)]);

        $this->expectException(LlmClientException::class);
        $this->expectExceptionMessage('[gemini] HTTP 400: API key not valid');

        $this->provider()->chat($this->messages(), new ChatOptions);
    }

    // ---- 串流 ----

    /** @param list<array<string, mixed>> $events */
    private function sse(array $events): string
    {
        return implode('', array_map(fn ($event) => 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE)."\r\n\r\n", $events));
    }

    /** 實測：每段都帶累計 usage；最後一段帶 finishReason，text 可能是空字串 */
    private function streamEvents(): array
    {
        $chunk = fn (string $text, int $out, ?string $finish = null) => [
            'candidates' => [array_filter(['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => $finish, 'index' => 0], fn ($v) => $v !== null)],
            'usageMetadata' => ['promptTokenCount' => 42, 'candidatesTokenCount' => $out],
            'modelVersion' => 'gemini-3.5-flash-lite',
        ];

        return [$chunk('RAG', 2), $chunk(' 是外部資料', 19), $chunk('', 33, 'STOP')];
    }

    public function test_stream_yields_text_and_last_chunk_carries_cumulative_usage(): void
    {
        Http::fake([self::STREAM_URL => Http::response($this->sse($this->streamEvents()))]);

        $chunks = iterator_to_array($this->provider()->stream($this->messages(), new ChatOptions), false);
        $last = end($chunks);

        $this->assertSame(
            [['RAG', ' 是外部資料', ''], 33, FinishReason::Stop, 'gemini-3.5-flash-lite'],
            [array_map(fn (StreamChunk $c) => $c->delta, $chunks), $last->usage?->outputTokens, $last->finishReason, $last->model],
        );
    }

    public function test_stream_without_finish_reason_is_rejected(): void
    {
        Http::fake([self::STREAM_URL => Http::response($this->sse(array_slice($this->streamEvents(), 0, 2)))]);

        $this->expectException(LlmResponseFormatException::class);

        iterator_to_array($this->provider()->stream($this->messages(), new ChatOptions), false);
    }
}
