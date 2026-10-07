<?php

namespace Tests\Feature\Rag;

use App\Ai\Chat\Providers\FakeChatProvider;
use App\Ai\Exceptions\LlmTimeoutException;
use App\Models\RagQueryLog;
use App\Rag\Answer\QuerySource;
use App\Rag\Evaluation\AnswerEvaluator;
use App\Rag\Evaluation\AnswerResult;
use App\Rag\Evaluation\ExpectedSource;
use App\Rag\Evaluation\QuestionType;
use App\Rag\Evaluation\TestQuestion;
use App\Rag\Retrieval\RetrievalResult;
use App\Rag\Retrieval\RetrieverService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\Support\SeedsRetrievedChunks;
use Tests\TestCase;

/**
 * AnswerEvaluator 與 rag:eval-answer。Retriever 以 mock 取代：問題含「宿舍」時沒有候選；LLM 回覆由 $reply 決定。
 */
class AnswerEvaluatorTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsRetrievedChunks;

    private string $reply = '依規定發給工資 [2]，並可遞延 [1][2]。';

    private bool $timeout = false;

    protected function setUp(): void
    {
        parent::setUp();

        $chunks = $this->seedChunks([['第十二條 特別休假', '第十二條', 0.6651], ['第四條 特別休假', '第四條', 0.6358]]);
        $this->mock(RetrieverService::class, fn (MockInterface $mock) => $mock->shouldReceive('retrieve')->andReturnUsing(
            fn (string $query) => new RetrievalResult(str_contains($query, '宿舍') ? [] : $chunks, 'fake', 5, 0.59, 0.5284),
        ));

        $test = $this;
        $this->app->instance(FakeChatProvider::class, new class($test) extends FakeChatProvider
        {
            public function __construct(private readonly AnswerEvaluatorTest $test) {}

            protected function reply(array $messages): string
            {
                return $this->test->reply();
            }
        });
    }

    public function reply(): string
    {
        if ($this->timeout) {
            throw new LlmTimeoutException('[ollama] Request timed out.');
        }

        return $this->reply;
    }

    private function evaluate(TestQuestion $question): AnswerResult
    {
        return $this->app->make(AnswerEvaluator::class)->evaluate([$question], 'fake')[0];
    }

    private static function answerable(string $question = '我的假沒休完怎麼辦？'): TestQuestion
    {
        return new TestQuestion('q08', QuestionType::Paraphrase, $question, [new ExpectedSource('員工管理辦法.pdf', '第十二條')]);
    }

    public function test_citations_are_legal_references_unique_and_sorted(): void
    {
        $this->assertSame([1, 2], $this->evaluate(self::answerable())->citations);
    }

    public function test_citation_hit_when_cited_source_is_expected(): void
    {
        $this->reply = '依規定發給工資 [1]。';

        $this->assertTrue($this->evaluate(self::answerable())->citationHit());
    }

    public function test_citation_miss_when_only_other_source_is_cited(): void
    {
        // 預期段落是第十二條，只引用了 [2]（第四條）
        $this->reply = '依規定發給工資 [2]。';

        $this->assertFalse($this->evaluate(self::answerable())->citationHit());
    }

    public function test_answerable_question_with_citation_passes(): void
    {
        $this->assertTrue($this->evaluate(self::answerable())->passed());
    }

    public function test_answer_without_citation_fails(): void
    {
        $this->reply = '依規定發給工資。';

        $this->assertFalse($this->evaluate(self::answerable())->passed());
    }

    public function test_answerable_question_answered_insufficient_fails(): void
    {
        $this->reply = '資料不足';

        $this->assertFalse($this->evaluate(self::answerable())->passed());
    }

    public function test_no_answer_question_passes_when_blocked_by_threshold(): void
    {
        $this->assertTrue($this->evaluate(new TestQuestion('q25', QuestionType::NoAnswer, '公司有提供員工宿舍嗎？', []))->passed());
    }

    public function test_no_answer_question_answered_by_llm_fails(): void
    {
        // 候選通過門檻、LLM 卻硬湊答案：第二道防線失守
        $this->assertFalse($this->evaluate(new TestQuestion('q27', QuestionType::NoAnswer, '員工餐廳幾點開始供餐？', []))->passed());
    }

    public function test_partial_question_passes_with_citation_even_when_part_is_insufficient(): void
    {
        // 「部分有答案」：答了一部分、另一部分資料不足，status 會是 insufficient_by_llm，是否正確只能人工判讀
        $this->reply = '未休之日數雇主應發給工資 [1]；年終獎金資料不足。';

        $this->assertTrue($this->evaluate(new TestQuestion('r08', QuestionType::Partial, '特休沒休完怎麼處理？會影響年終獎金嗎？', []))->passed());
    }

    public function test_llm_timeout_is_recorded_without_retry_and_other_questions_continue(): void
    {
        $this->timeout = true;

        $results = $this->app->make(AnswerEvaluator::class)->evaluate([
            self::answerable(), new TestQuestion('q25', QuestionType::NoAnswer, '公司有提供員工宿舍嗎？', []),
        ], 'fake');

        $this->assertSame(
            [null, 'App\\Ai\\Exceptions\\LlmTimeoutException：[ollama] Request timed out.', false, true],
            [$results[0]->answer, $results[0]->error, $results[0]->passed(), $results[1]->passed()],
        );
    }

    public function test_evaluation_is_logged_as_eval_source(): void
    {
        $this->evaluate(self::answerable());

        $this->assertSame(QuerySource::Eval, RagQueryLog::sole()->source_key);
    }

    public function test_command_writes_report_with_manual_review_column(): void
    {
        $set = tempnam(sys_get_temp_dir(), 'rag-set');
        file_put_contents($set, implode("\n", [
            json_encode(['id' => 'q08', 'type' => 'paraphrase', 'question' => '我的假沒休完怎麼辦？', 'expected' => [['document' => '員工管理辦法.pdf', 'section' => '第十二條']]]),
            json_encode(['id' => 'q25', 'type' => 'no_answer', 'question' => '公司有提供員工宿舍嗎？', 'expected' => []]),
        ]));
        $dir = sys_get_temp_dir().'/rag-answers-'.uniqid();

        $this->artisan('rag:eval-answer', ['--provider' => 'fake', '--set' => $set, '--output-dir' => $dir])->assertSuccessful();

        $report = File::get("{$dir}/ch09-answers-fake.md");
        File::deleteDirectory($dir);
        $this->assertSame([true, true, true, true, true, true, true], [
            str_contains($report, '| 無答案題回答資料不足 | 1 / 1 |'),
            str_contains($report, '| 有答案題：未答資料不足且標示 [n] | 1 / 1 |'),
            str_contains($report, '| 引用率（已回答題中至少一個合法引用） | 1 / 1 |'),
            str_contains($report, '| 命中率（已回答題中引用到預期段落） | 1 / 1 |'),
            str_contains($report, '| 平均引用數（已回答題） | 2.00 |'),
            str_contains($report, '| 無答案題未顯示來源 | 1 / 1 |'),
            str_contains($report, '| q25 | 無答案 | 公司有提供員工宿舍嗎？ | insufficient_no_candidates | 否 | 0.5284 | 資料不足 | — | — | — | ✓ | — | — | — |  |'),
        ]);
    }

    public function test_command_label_and_expected_answer_column(): void
    {
        $set = tempnam(sys_get_temp_dir(), 'rag-set');
        file_put_contents($set, json_encode(['id' => 'r05', 'type' => 'reasoning', 'question' => '我服務滿一年半，有幾天特休？',
            'expected' => [['document' => '員工管理辦法.pdf', 'section' => '第十二條']], 'expected_answer' => '7 天']));
        $dir = sys_get_temp_dir().'/rag-answers-'.uniqid();

        $this->artisan('rag:eval-answer', ['--provider' => 'fake', '--set' => $set, '--output-dir' => $dir, '--label' => 'reasoning'])->assertSuccessful();

        $report = File::get("{$dir}/ch09-answers-fake-reasoning.md");
        File::deleteDirectory($dir);
        $this->assertStringContainsString('| r05 | 推理 | 我服務滿一年半，有幾天特休？ | 7 天 | answered | 是 |', $report);
    }
}
