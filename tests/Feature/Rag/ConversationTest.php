<?php

namespace Tests\Feature\Rag;

use App\Ai\Chat\ChatService;
use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\ChatResult;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Chat\DTO\Usage;
use App\Ai\Chat\Providers\FakeChatProvider;
use App\Ai\Exceptions\LlmTimeoutException;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\RagQueryLog;
use App\Rag\Answer\AnswerOptions;
use App\Rag\Answer\AnswerStatus;
use App\Rag\Answer\QuerySource;
use App\Rag\Answer\RagAnswer;
use App\Rag\Answer\RagAnswerService;
use App\Rag\Citation\CitationParser;
use App\Rag\Conversation\HistoryWindow;
use App\Rag\Conversation\RewriteStatus;
use App\Rag\Conversation\Turn;
use App\Rag\Evaluation\ConversationCase;
use App\Rag\Evaluation\ConversationEvaluator;
use App\Rag\Evaluation\ExpectedSource;
use App\Rag\Retrieval\RetrievalResult;
use App\Rag\Retrieval\RetrievedChunk;
use App\Rag\Retrieval\RetrieveOptions;
use App\Rag\Retrieval\RetrieverService;
use App\Repositories\ConversationRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsRetrievedChunks;
use Tests\TestCase;

/**
 * 多輪對話 RAG（Ch13）：Sliding Window、Query Rewriting、降級、對話紀錄保存。
 * Retriever 以 mock 取代；LLM 使用 ScriptedChatProvider（改寫與回答各自回覆指定內容）。
 */
class ConversationTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsRetrievedChunks;

    private ScriptedChatProvider $llm;

    /** @var list<string> Retriever 收到的問題 */
    private array $retrievedQueries = [];

    /** @var list<RetrievedChunk> */
    private array $chunks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->llm = new ScriptedChatProvider;
        $this->app->instance(FakeChatProvider::class, $this->llm);
        $this->chunks = $this->seedChunks([['第十二條 特別休假 服務滿二年以上未滿三年者，十日。', '第十二條', 0.66]]);

        $this->mock(RetrieverService::class, fn (MockInterface $mock) => $mock->shouldReceive('retrieve')->andReturnUsing(function (string $query, RetrieveOptions $options) {
            $this->retrievedQueries[] = $query;

            return new RetrievalResult(str_contains($query, '宿舍') ? [] : $this->chunks, 'fake', 5, 0.59);
        }));
    }

    private function ask(string $question, ?int $conversationId = null, QuerySource $source = QuerySource::Cli, string $provider = 'fake'): RagAnswer
    {
        return $this->app->make(RagAnswerService::class)->answer($question, new AnswerOptions($provider, $source, $conversationId));
    }

    /** 建立一段已有一輪的對話（第一輪的回答含 [1]） */
    private function conversationWithFirstTurn(string $provider = 'fake'): int
    {
        $this->llm->answerReply = '特別休假依年資給予，服務滿二年以上未滿三年者十日 [1]。';

        return $this->ask('公司的特休規定是什麼？', provider: $provider)->conversationId;
    }

    // ---- 第一輪 ----

    public function test_first_turn_does_not_call_rewrite_llm(): void
    {
        $answer = $this->ask('公司的特休規定是什麼？');

        $this->assertSame(
            [RewriteStatus::Skipped, false, ['answer'], ['公司的特休規定是什麼？']],
            [$answer->rewrite->status, $answer->rewrite->called, $this->llm->kinds(), $this->retrievedQueries],
        );
    }

    public function test_api_without_conversation_id_creates_conversation_and_keeps_single_turn_behavior(): void
    {
        $response = $this->postJson('/api/knowledge/ask', ['question' => '公司的特休規定是什麼？', 'provider' => 'fake'])->assertOk();

        $this->assertSame(
            ['answered', 'skipped', false, '公司的特休規定是什麼？', true],
            [$response['status'], $response['rewrite_status'], $response['rewrite_called'], $response['rewritten_question'], is_int($response['conversation_id'])],
        );
    }

    // ---- 追問 ----

    public function test_follow_up_is_rewritten_and_retriever_receives_rewritten_question(): void
    {
        $id = $this->conversationWithFirstTurn();
        $this->llm->rewriteReply = '服務滿兩年的員工有幾天特休？';

        $answer = $this->ask('那兩年年資呢？', $id);

        $this->assertSame(
            [RewriteStatus::Rewritten, '服務滿兩年的員工有幾天特休？', '服務滿兩年的員工有幾天特休？', '那兩年年資呢？'],
            [$answer->rewrite->status, $answer->rewrite->question, end($this->retrievedQueries), $answer->originalQuestion],
        );
    }

    public function test_answer_llm_receives_history_reference_and_original_question(): void
    {
        $id = $this->conversationWithFirstTurn();
        $this->llm->rewriteReply = '服務滿兩年的員工有幾天特休？';

        $this->ask('那兩年年資呢？', $id);

        $answerCall = $this->llm->call('answer', 1);
        $this->assertSame(
            [Role::System, Role::User, Role::Assistant, Role::User, '公司的特休規定是什麼？', '特別休假依年資給予，服務滿二年以上未滿三年者十日。', true],
            [$answerCall[0]->role, $answerCall[1]->role, $answerCall[2]->role, $answerCall[3]->role, $answerCall[1]->content, $answerCall[2]->content, str_ends_with($answerCall[3]->content, '問題：那兩年年資呢？')],
        );
    }

    public function test_history_sent_to_rewrite_has_no_citation_markers(): void
    {
        $id = $this->conversationWithFirstTurn();

        $this->ask('那兩年年資呢？', $id);

        $this->assertSame([false, true], [str_contains($this->llm->call('rewrite', 0)[1]->content, '[1]'), str_contains($this->llm->call('rewrite', 0)[1]->content, '十日')]);
    }

    /** 讓 ollama、openai、gemini 都由 ScriptedChatProvider 回應，檢查改寫選到哪一組設定 */
    private function routeProvidersToScript(): void
    {
        foreach (['ollama', 'openai', 'gemini'] as $name) {
            config()->set("llm.chat.providers.{$name}", FakeChatProvider::class);
        }
        $this->app->forgetInstance(ChatService::class);
    }

    /** @return array<string, array{string, array<string, mixed>, int}> */
    public static function followedProviders(): array
    {
        return [
            'ollama：think 開啟、120 秒' => ['ollama', ['ollama' => ['think' => true]], 120],
            'openai：15 秒' => ['openai', ['openai' => []], 15],
        ];
    }

    #[DataProvider('followedProviders')]
    public function test_follow_uses_rewrite_settings_of_the_answer_provider_given_per_request(string $provider, array $providerOptions, int $timeout): void
    {
        $this->routeProvidersToScript();
        $id = $this->conversationWithFirstTurn($provider);

        $this->ask('那兩年年資呢？', $id, provider: $provider);

        $options = $this->llm->options('rewrite', 0);
        $this->assertSame([0.0, $providerOptions, $timeout], [$options->temperature, $options->providerOptions, $options->timeout]);
    }

    public function test_blank_rewrite_model_uses_the_provider_default_model(): void
    {
        // phpunit.xml 的 RAG_REWRITE_OLLAMA_MODEL 為空字串：不能當成模型名稱送出
        $this->routeProvidersToScript();
        $id = $this->conversationWithFirstTurn('ollama');

        $this->ask('那兩年年資呢？', $id, provider: 'ollama');

        $this->assertNull($this->llm->options('rewrite', 0)->model);
    }

    public function test_explicit_rewrite_provider_overrides_follow(): void
    {
        $this->routeProvidersToScript();
        config()->set('rag.conversation.rewrite.provider', 'openai');
        $id = $this->conversationWithFirstTurn('ollama');

        $this->ask('那兩年年資呢？', $id, provider: 'ollama');

        $this->assertSame([['openai' => []], 15], [$this->llm->options('rewrite', 0)->providerOptions, $this->llm->options('rewrite', 0)->timeout]);
    }

    public function test_provider_without_rewrite_settings_falls_back_without_using_another_provider(): void
    {
        $this->routeProvidersToScript();
        $id = $this->conversationWithFirstTurn('gemini');
        Log::spy();

        $answer = $this->ask('那兩年年資呢？', $id, provider: 'gemini');

        $this->assertSame([RewriteStatus::Fallback, false, 'no_rewrite_config', '那兩年年資呢？', ['answer', 'answer']],
            [$answer->rewrite->status, $answer->rewrite->called, $answer->rewrite->failureReason, end($this->retrievedQueries), $this->llm->kinds()]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'rewrite.fallback' && $context['provider'] === 'gemini' && $context['reason'] === 'no_rewrite_config')->once();
    }

    /** @return array<string, array{string, RewriteStatus}> */
    public static function unchangedRewrites(): array
    {
        return [
            '完全相同' => ['那兩年年資呢？', RewriteStatus::Unchanged],
            '只差空白與標點' => ['那兩年 年資呢?', RewriteStatus::Unchanged],
            '有補上對象' => ['服務滿兩年的員工有幾天特休？', RewriteStatus::Rewritten],
        ];
    }

    #[DataProvider('unchangedRewrites')]
    public function test_rewrite_same_as_question_is_unchanged(string $reply, RewriteStatus $status): void
    {
        $id = $this->conversationWithFirstTurn();
        $this->llm->rewriteReply = $reply;

        $rewrite = $this->ask('那兩年年資呢？', $id)->rewrite;

        $this->assertSame([$status, true], [$rewrite->status, $rewrite->called]);
    }

    public function test_history_budget_keeps_fewer_turns_and_logs_kept_count(): void
    {
        Log::spy();
        $long = str_repeat('長', 1000);

        $answer = $this->app->make(RagAnswerService::class)->answer('那兩年年資呢？', new AnswerOptions('fake', QuerySource::Eval, history: [new Turn('一', $long), new Turn('二', $long), new Turn('三', '短')]));

        $this->assertSame(2, $answer->historyTurns);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'conversation.history' && $context['in_window'] === 3 && $context['kept'] === 2)->once();
    }

    // ---- 降級 ----

    /** @return array<string, array{string|null, string}> */
    public static function badRewrites(): array
    {
        return [
            '逾時' => [null, 'LlmTimeoutException'],
            '空白' => ['  ', 'empty'],
            '過長' => [str_repeat('特休', 101), 'too_long'],
            '像答案' => ['資料不足', 'looks_like_answer'],
        ];
    }

    #[DataProvider('badRewrites')]
    public function test_failed_rewrite_falls_back_to_original_question(?string $reply, string $reason): void
    {
        $id = $this->conversationWithFirstTurn();
        Log::spy();
        $this->llm->rewriteReply = $reply;

        $answer = $this->ask('那兩年年資呢？', $id);

        $this->assertSame([RewriteStatus::Fallback, '那兩年年資呢？', '那兩年年資呢？', AnswerStatus::Answered, true],
            [$answer->rewrite->status, $answer->rewrite->question, end($this->retrievedQueries), $answer->status, str_contains($answer->rewrite->failureReason, $reason)]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'rewrite.fallback')->once();
    }

    // ---- 資料不足 ----

    public function test_no_candidates_after_rewrite_does_not_call_answer_llm(): void
    {
        $id = $this->conversationWithFirstTurn();
        $this->llm->rewriteReply = '公司有提供員工宿舍嗎？';

        $answer = $this->ask('那宿舍呢？', $id);

        $this->assertSame([AnswerStatus::InsufficientNoCandidates, false, true, ['answer', 'rewrite']], [$answer->status, $answer->llmCalled, $answer->rewrite->called, $this->llm->kinds()]);
    }

    // ---- 保存與紀錄 ----

    public function test_exchange_is_saved_with_rewrite_and_raw_answer(): void
    {
        $id = $this->conversationWithFirstTurn();
        $this->llm->rewriteReply = '服務滿兩年的員工有幾天特休？';
        $this->ask('那兩年年資呢？', $id);

        $messages = ConversationMessage::where('conversation_id', $id)->orderBy('id')->get();
        $this->assertSame(
            [4, '那兩年年資呢？', '服務滿兩年的員工有幾天特休？', RewriteStatus::Rewritten, '特別休假依年資給予，服務滿二年以上未滿三年者十日 [1]。'],
            [$messages->count(), $messages[2]->content, $messages[2]->rewritten_question, $messages[2]->rewrite_status_key, $messages[1]->content],
        );
    }

    public function test_query_log_records_rewrite(): void
    {
        $id = $this->conversationWithFirstTurn();
        $this->llm->rewriteReply = '服務滿兩年的員工有幾天特休？';
        $this->ask('那兩年年資呢？', $id);

        $log = RagQueryLog::latest('id')->first();
        $this->assertSame([$id, '那兩年年資呢？', '服務滿兩年的員工有幾天特休？', RewriteStatus::Rewritten], [$log->conversation_id, $log->question, $log->rewritten_question, $log->rewrite_status_key]);
    }

    public function test_eval_source_does_not_create_conversation(): void
    {
        $this->assertNull($this->ask('公司的特休規定是什麼？', source: QuerySource::Eval)->conversationId);
    }

    public function test_api_continues_conversation_and_rejects_unknown_id(): void
    {
        $id = $this->conversationWithFirstTurn();
        $this->llm->rewriteReply = '服務滿兩年的員工有幾天特休？';

        $this->postJson('/api/knowledge/ask', ['question' => '那兩年年資呢？', 'provider' => 'fake', 'conversation_id' => $id])
            ->assertOk()->assertJson(['conversation_id' => $id, 'rewrite_status' => 'rewritten', 'rewritten_question' => '服務滿兩年的員工有幾天特休？']);
        $this->postJson('/api/knowledge/ask', ['question' => '那兩年年資呢？', 'conversation_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
    }

    public function test_conversation_disabled_ignores_history(): void
    {
        $id = $this->conversationWithFirstTurn();
        config()->set('rag.conversation.enabled', false);

        $answer = $this->ask('那兩年年資呢？', $id);

        $this->assertSame([null, RewriteStatus::Skipped], [$answer->conversationId, $answer->rewrite->status]);
    }

    // ---- 多輪評估 ----

    private function evaluationCase(): ConversationCase
    {
        return new ConversationCase('h01', 'ellipsis', [new Turn('識別證遺失要多少錢？', '375 元 [1]。'), new Turn('公司的特休規定是什麼？', '依年資給予 [1]。')], '那兩年年資呢？', [new ExpectedSource('員工管理辦法.pdf', '第十二條')]);
    }

    /** @return array<string, array{string, int, string}> */
    public static function evaluationModes(): array
    {
        return [
            'none 只用追問' => ['none', 3, '那兩年年資呢？'],
            'concat 串接 Window 內的歷史問題' => ['concat', 3, '識別證遺失要多少錢？ 公司的特休規定是什麼？ 那兩年年資呢？'],
            'concat 受 Window 限制' => ['concat', 1, '公司的特休規定是什麼？ 那兩年年資呢？'],
            'llm 使用改寫結果' => ['llm', 3, '服務滿兩年的員工有幾天特休？'],
        ];
    }

    #[DataProvider('evaluationModes')]
    public function test_evaluator_builds_query_by_mode(string $mode, int $window, string $query): void
    {
        $this->llm->rewriteReply = '服務滿兩年的員工有幾天特休？';

        $result = $this->app->make(ConversationEvaluator::class)->evaluate([$this->evaluationCase()], $mode, $window)[0];

        $this->assertSame([$query, $query, 1], [$result->query, $this->retrievedQueries[0], $result->rank]);
    }

    public function test_evaluator_answer_uses_scripted_history_without_creating_conversation(): void
    {
        $before = Conversation::count();

        $result = $this->app->make(ConversationEvaluator::class)->evaluate([$this->evaluationCase()], 'none', answer: true)[0];

        $this->assertSame([10, 0, 2], [$result->answerInputTokens, Conversation::count() - $before, count(array_filter($this->llm->call('answer', 0), fn (Message $m) => $m->role === Role::Assistant))]);
    }

    public function test_eval_conversation_command_writes_report(): void
    {
        $dir = sys_get_temp_dir().'/ch13-eval-'.uniqid();
        $set = "{$dir}/set.jsonl";
        File::ensureDirectoryExists($dir);
        File::put($set, json_encode(['id' => 'h01', 'type' => 'ellipsis', 'history' => [['question' => '公司的特休規定是什麼？', 'answer' => '依年資給予 [1]。']], 'question' => '那兩年年資呢？', 'expected' => [['document' => '員工管理辦法.pdf', 'section' => '第十二條']]], JSON_UNESCAPED_UNICODE)."\n");

        $this->artisan('rag:eval-conversation', ['--rewrite' => 'none', '--set' => $set, '--output-dir' => $dir, '--label' => 'test'])->assertSuccessful();

        $this->assertStringContainsString('| 全部 | 1 | 100% | 100% | 1.000 |', File::get(File::glob("{$dir}/ch13-eval-none-test-*.md")[0]));
        File::deleteDirectory($dir);
    }

    public function test_eval_conversation_command_overrides_think_and_separates_ambiguous_cases(): void
    {
        $this->routeProvidersToScript();
        $dir = sys_get_temp_dir().'/ch13-eval-'.uniqid();
        $set = "{$dir}/set.jsonl";
        $case = fn (string $id, string $type) => json_encode(['id' => $id, 'type' => $type, 'history' => [['question' => '公司的特休規定是什麼？', 'answer' => '依年資給予 [1]。']], 'question' => '那兩年年資呢？', 'expected' => [['document' => '其他文件.pdf', 'section' => '第一條']]], JSON_UNESCAPED_UNICODE);
        File::ensureDirectoryExists($dir);
        File::put($set, $case('h01', 'ellipsis')."\n".$case('h13', 'ambiguous')."\n");

        $this->artisan('rag:eval-conversation', ['--rewrite' => 'llm', '--rewrite-provider' => 'ollama', '--rewrite-think' => 'on', '--set' => $set, '--output-dir' => $dir, '--label' => 'test'])->assertSuccessful();

        $report = File::get(File::glob("{$dir}/ch13-eval-llm-test-*.md")[0]);
        $this->assertSame([true, true, true], [str_contains($report, '| 全部 | 1 | 0% | 0% | 0.000 |'), str_contains($report, '| h13 | 那兩年年資呢？ |'), str_contains($report, '"think":true')]);
        File::deleteDirectory($dir);
    }

    // ---- Sliding Window ----

    private static function window(int $turns, int $budget): HistoryWindow
    {
        return new HistoryWindow(new CitationParser, $turns, $budget);
    }

    public function test_window_keeps_only_last_n_turns(): void
    {
        $turns = [new Turn('第一題', '答一'), new Turn('第二題', '答二'), new Turn('第三題', '答三'), new Turn('第四題', '答四')];

        $this->assertSame(['第二題', '第三題', '第四題'], array_map(fn (Turn $t) => $t->question, self::window(3, 1500)->apply($turns)));
    }

    public function test_window_drops_oldest_turns_over_budget(): void
    {
        $turns = [new Turn('第一題', str_repeat('甲', 50)), new Turn('第二題', str_repeat('乙', 50))];

        $this->assertSame(['第二題'], array_map(fn (Turn $t) => $t->question, self::window(3, 80)->apply($turns)));
    }

    public function test_window_removes_citations_and_source_lines(): void
    {
        $turn = new Turn('特休？', "未休之日數雇主應發給工資 [1][2]。年終獎金 [資料不足]。\n資料來源：\n[1] 員工管理辦法.pdf　第十二條　第 2 頁");

        $this->assertSame('未休之日數雇主應發給工資。年終獎金。', self::window(3, 1500)->apply([$turn])[0]->answer);
    }

    public function test_repository_reads_turns_in_order_with_limit(): void
    {
        $repository = new ConversationRepository;
        $id = $repository->create('fake')->id;
        foreach (['一', '二', '三', '四'] as $n) {
            $repository->addExchange($id, "問題{$n}", "問題{$n}", RewriteStatus::Skipped, "回答{$n}", AnswerStatus::Answered);
        }

        $this->assertSame(['問題二', '問題三', '問題四'], array_map(fn (Turn $t) => $t->question, $repository->recentTurns($id, 3)));
    }
}

/**
 * 依 System Prompt 分辨改寫與回答，各自回覆指定內容；rewriteReply 為 null 時模擬逾時。記錄每次呼叫的訊息與參數。
 */
class ScriptedChatProvider extends FakeChatProvider
{
    public ?string $rewriteReply = '改寫後的問題';

    public string $answerReply = '依規定辦理 [1]。';

    /** @var list<array{kind: string, messages: list<Message>, options: ChatOptions}> */
    public array $calls = [];

    public function chat(array $messages, ChatOptions $options): ChatResult
    {
        $kind = str_starts_with($messages[0]->content, '依據以下對話紀錄') ? 'rewrite' : 'answer';
        $this->calls[] = ['kind' => $kind, 'messages' => $messages, 'options' => $options];

        if ($kind === 'rewrite' && $this->rewriteReply === null) {
            throw new LlmTimeoutException('[fake] Request timed out.');
        }

        return new ChatResult($kind === 'rewrite' ? $this->rewriteReply : $this->answerReply, new Usage(10, 20), 'fake', FinishReason::Stop);
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_column($this->calls, 'kind');
    }

    /** @return list<Message> 第 n 次（從 0 起）該類呼叫的訊息 */
    public function call(string $kind, int $n): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['kind'] === $kind))[$n]['messages'];
    }

    public function options(string $kind, int $n): ChatOptions
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['kind'] === $kind))[$n]['options'];
    }
}
