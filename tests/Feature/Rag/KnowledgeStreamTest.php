<?php

namespace Tests\Feature\Rag;

use App\Ai\Chat\ChatService;
use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\ChatResult;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Chat\DTO\Usage;
use App\Ai\Chat\Providers\FakeChatProvider;
use App\Ai\Exceptions\LlmConnectionException;
use App\Models\ConversationMessage;
use App\Rag\Answer\AnswerOptions;
use App\Rag\Answer\AnswerStatus;
use App\Rag\Answer\QuerySource;
use App\Rag\Answer\RagAnswerService;
use App\Rag\Answer\RagProgressListener;
use App\Rag\Answer\RagStage;
use App\Rag\Conversation\RewriteResult;
use App\Rag\Retrieval\RetrievalResult;
use App\Rag\Retrieval\RetrieveOptions;
use App\Rag\Retrieval\RetrieverService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\SeedsRetrievedChunks;
use Tests\TestCase;
use Throwable;

/**
 * 串流問答（Ch14）：事件協定、資料不足短路、與非串流結果一致、引用清理、中斷、錯誤不洩漏內部資訊。
 * Retriever 以 mock 取代；LLM 使用 StreamingScriptedProvider。
 */
class KnowledgeStreamTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsRetrievedChunks;

    private StreamingScriptedProvider $llm;

    /** @var list<string> Retriever 收到的問題 */
    private array $retrievedQueries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->llm = new StreamingScriptedProvider;
        $this->app->instance(FakeChatProvider::class, $this->llm);
        $chunks = $this->seedChunks([['第十二條 特別休假 服務滿二年以上未滿三年者，十日。', '第十二條', 0.66]]);

        $this->mock(RetrieverService::class, fn (MockInterface $mock) => $mock->shouldReceive('retrieve')->andReturnUsing(function (string $query, RetrieveOptions $options) use ($chunks) {
            $this->retrievedQueries[] = $query;

            return new RetrievalResult(str_contains($query, '宿舍') ? [] : $chunks, 'fake', 5, 0.59);
        }));
    }

    /** @return list<array{string, array<string, mixed>}> [事件名稱, data] */
    private function stream(array $payload): array
    {
        $content = $this->post('/api/knowledge/ask/stream', ['provider' => 'fake', ...$payload], ['Accept' => 'text/event-stream'])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->assertHeader('X-Accel-Buffering', 'no')
            ->streamedContent();

        preg_match_all('/^event: (\S+)\ndata: (.*)\n\n/m', $content, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => [$m[1], json_decode($m[2], true)], $matches);
    }

    /** @param list<array{string, array<string, mixed>}> $events */
    private static function names(array $events): array
    {
        return array_map(fn (array $e) => $e[0] === 'stage' ? "stage:{$e[1]['stage']}" : $e[0], $events);
    }

    /** @param list<array{string, array<string, mixed>}> $events */
    private static function done(array $events): array
    {
        return collect($events)->firstWhere(0, 'done')[1];
    }

    public function test_events_follow_the_protocol_order(): void
    {
        $this->llm->answerReply = '服務滿二年以上未滿三年者，十日 [1]。';
        $deltas = (int) ceil(mb_strlen($this->llm->answerReply) / 4);

        $names = self::names($this->stream(['question' => '特休兩年幾天？']));

        $this->assertSame(
            ['conversation', 'stage:rewriting', 'rewrite', 'stage:retrieving', 'retrieval', 'stage:generating', ...array_fill(0, $deltas, 'delta'), 'done'],
            $names,
        );
    }

    public function test_no_candidates_sends_done_without_generation_or_llm_call(): void
    {
        $events = $this->stream(['question' => '公司有員工宿舍嗎？']);

        $this->assertSame([['conversation', 'stage:rewriting', 'rewrite', 'stage:retrieving', 'retrieval', 'done'], false, 'insufficient_no_candidates', 0],
            [self::names($events), self::done($events)['llm_called'], self::done($events)['status'], $this->llm->streamCalls]);
    }

    public function test_stream_done_matches_non_stream_answer(): void
    {
        $this->llm->answerReply = '服務滿二年以上未滿三年者，十日 [1]。';
        $keys = ['answer', 'status', 'llm_called', 'citations', 'sources', 'references', 'warnings', 'rewrite_status', 'provider', 'model', 'usage'];

        $json = $this->postJson('/api/knowledge/ask', ['question' => '特休兩年幾天？', 'provider' => 'fake'])->assertOk()->json();
        $done = self::done($this->stream(['question' => '特休兩年幾天？']));

        $this->assertSame(array_intersect_key($json, array_flip($keys)), array_intersect_key($done, array_flip($keys)));
    }

    public function test_out_of_range_citation_is_shown_while_streaming_but_removed_in_done(): void
    {
        $this->llm->answerReply = '十日 [1]，另見 [7]。';

        $events = $this->stream(['question' => '特休兩年幾天？']);
        $streamed = implode('', array_map(fn (array $e) => $e[1]['text'], array_filter($events, fn (array $e) => $e[0] === 'delta')));

        $this->assertSame([true, false, '[7]'], [str_contains($streamed, '[7]'), str_contains(self::done($events)['answer'], '[7]'), self::done($events)['warnings']['invalid_refs'][0]['raw']]);
    }

    public function test_interrupted_answer_stops_reading_and_is_excluded_from_history(): void
    {
        $rag = $this->app->make(RagAnswerService::class);
        $this->llm->answerReply = '特別休假依年資給予，服務滿二年以上未滿三年者十日 [1]。';
        $id = $rag->answer('公司的特休規定是什麼？', new AnswerOptions('fake', QuerySource::Cli))->conversationId;

        $this->llm->answerReply = '事假全年不得超過十四日，這段回答很長很長很長。';
        $interrupted = $rag->answer('事假可以請幾天？', new AnswerOptions('fake', QuerySource::Cli, $id), new CancelAfterFirstDelta);

        $this->llm->rewriteReply = '服務滿兩年的員工有幾天特休？';
        $rag->answer('那兩年年資呢？', new AnswerOptions('fake', QuerySource::Cli, $id));
        $history = $this->llm->lastRewriteHistory;

        $this->assertSame(
            [AnswerStatus::Interrupted, 1, AnswerStatus::Interrupted, true, false],
            [
                $interrupted->status,
                $this->llm->chunksRead,
                ConversationMessage::where('conversation_id', $id)->where('role_key', 'assistant')->orderBy('id')->get()[1]->status_key,
                str_contains($history, '公司的特休規定是什麼？'),
                str_contains($history, '事假可以請幾天？'),
            ],
        );
    }

    /** @return array<string, array{Throwable, string}> */
    public static function failures(): array
    {
        return [
            '連不上模型服務' => [new LlmConnectionException('[ollama] cURL error 7: Failed to connect to 127.0.0.1 port 11434 (/Users/someone/app/vendor/x.php)'), 'provider_unavailable'],
            '非預期的例外' => [new RuntimeException('SQLSTATE[HY000] at /Users/someone/app/app/Rag/X.php:42'), 'internal_error'],
        ];
    }

    #[DataProvider('failures')]
    public function test_error_event_does_not_leak_exception_details(Throwable $failure, string $type): void
    {
        $this->llm->failure = $failure;

        $events = $this->stream(['question' => '特休兩年幾天？']);
        $error = collect($events)->firstWhere(0, 'error')[1];

        $this->assertSame([$type, false, false], [$error['type'], str_contains(json_encode($events), '127.0.0.1'), str_contains(json_encode($events), '/Users/')]);
    }

    public function test_switching_provider_within_a_conversation_is_rejected(): void
    {
        $id = $this->postJson('/api/knowledge/ask', ['question' => '公司的特休規定是什麼？', 'provider' => 'fake'])->json('conversation_id');

        $this->postJson('/api/knowledge/ask/stream', ['question' => '那兩年年資呢？', 'provider' => 'ollama', 'conversation_id' => $id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider');
    }

    public function test_conversation_without_provider_uses_the_one_it_was_created_with(): void
    {
        $id = $this->postJson('/api/knowledge/ask', ['question' => '公司的特休規定是什麼？', 'provider' => 'fake'])->json('conversation_id');
        config()->set('llm.chat.default', 'ollama');
        $this->app->forgetInstance(ChatService::class);

        $this->postJson('/api/knowledge/ask', ['question' => '那兩年年資呢？', 'conversation_id' => $id])->assertOk()->assertJson(['provider' => 'fake']);
    }

    public function test_chat_page_lists_only_providers_with_rewrite_settings(): void
    {
        $this->withoutVite();

        $this->get('/knowledge/chat')->assertOk()->assertSee('Ollama')->assertSee('OpenAI')->assertDontSee('Gemini')->assertDontSee('value="fake"', false);
    }

    public function test_chat_script_does_not_persist_conversation_across_reloads(): void
    {
        // 保留 conversation_id 卻看不到先前的訊息時，下一題會被當成追問改寫：重新載入就是新對話
        $this->assertSame(0, preg_match('/sessionStorage|localStorage/', file_get_contents(resource_path('js/knowledge-chat.js'))));
    }

    public function test_cors_allows_only_the_app_origin(): void
    {
        $app = rtrim(config('app.url'), '/');
        $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/knowledge/ask/stream', server: [
            'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);

        // 只允許單一來源時，套件固定回傳該來源；瀏覽器比對不符（evil.example ≠ APP_URL）就擋下讀取
        $this->assertSame([$app, $app], [
            $preflight('https://evil.example')->headers->get('Access-Control-Allow-Origin'),
            $preflight($app)->headers->get('Access-Control-Allow-Origin'),
        ]);
    }

    public function test_cross_origin_response_does_not_allow_reading(): void
    {
        $response = $this->postJson('/api/knowledge/ask', ['question' => '公司有提供員工宿舍嗎？', 'provider' => 'fake'], ['Origin' => 'https://evil.example']);

        $this->assertNotContains($response->headers->get('Access-Control-Allow-Origin'), ['*', 'https://evil.example']);
    }

    public function test_chat_script_never_inserts_html(): void
    {
        // 模型輸出、文件名稱、改寫問題都以 textContent 插入（XSS）；這個檢查防止日後改用 innerHTML
        $this->assertSame(0, preg_match('/\.(innerHTML|outerHTML)\s*\+?=|insertAdjacentHTML\s*\(|document\.write\s*\(/', file_get_contents(resource_path('js/knowledge-chat.js'))));
    }
}

/**
 * 改寫一律走 chat()（System Prompt 以「依據以下對話紀錄」開頭）；回答在非串流時走 chat()、串流時走 stream()（每 4 字一段，最後一段帶 usage）。
 * 記錄串流被讀了幾段，用來確認中斷後停止讀取。
 */
class StreamingScriptedProvider extends FakeChatProvider
{
    public string $answerReply = '依規定辦理 [1]。';

    public string $rewriteReply = '改寫後的問題';

    public ?Throwable $failure = null;

    public int $streamCalls = 0;

    public int $chunksRead = 0;

    public string $lastRewriteHistory = '';

    public function chat(array $messages, ChatOptions $options): ChatResult
    {
        $rewrite = str_starts_with($messages[0]->content, '依據以下對話紀錄');
        if ($rewrite) {
            $this->lastRewriteHistory = $messages[1]->content;
        }

        return new ChatResult($rewrite ? $this->rewriteReply : $this->answerReply, new Usage(10, 20), 'fake', FinishReason::Stop);
    }

    public function stream(array $messages, ChatOptions $options): iterable
    {
        $this->streamCalls++;
        $this->chunksRead = 0;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $pieces = mb_str_split($this->answerReply, 4);
        foreach ($pieces as $i => $piece) {
            $this->chunksRead++;
            yield $i === array_key_last($pieces) ? new StreamChunk($piece, new Usage(10, 20), FinishReason::Stop, 'fake') : new StreamChunk($piece);
        }
    }
}

/** 收到第一段回答後就表示呼叫端已離開 */
class CancelAfterFirstDelta implements RagProgressListener
{
    private bool $received = false;

    public function conversation(?int $conversationId): void {}

    public function stage(RagStage $stage): void {}

    public function rewrite(RewriteResult $rewrite): void {}

    public function retrieval(RetrievalResult $retrieval): void {}

    public function delta(string $text): void
    {
        $this->received = true;
    }

    public function cancelled(): bool
    {
        return $this->received;
    }
}
