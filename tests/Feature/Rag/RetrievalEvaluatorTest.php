<?php

namespace Tests\Feature\Rag;

use App\Rag\Evaluation\ExpectedSource;
use App\Rag\Evaluation\QuestionType;
use App\Rag\Evaluation\RetrievalEvaluator;
use App\Rag\Evaluation\TestQuestion;
use App\Rag\Retrieval\KeywordOnlyPolicy;
use App\Rag\Retrieval\RetrievalMode;
use App\Rag\Retrieval\RetrievalResult;
use App\Rag\Retrieval\RetrievedChunk;
use App\Rag\Retrieval\RetrieveOptions;
use App\Rag\Retrieval\RetrieverService;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * RetrievalEvaluator 與 rag:eval。RetrieverService 以 mock 取代：依題目回傳預先排好的 Chunk。
 */
class RetrievalEvaluatorTest extends TestCase
{
    private static function chunk(string $document, ?string $section, float $score): RetrievedChunk
    {
        return new RetrievedChunk(1, 1, $document, $section, 1, 1, '內容', $score);
    }

    private static function question(string $id, QuestionType $type, array $expected = []): TestQuestion
    {
        return new TestQuestion($id, $type, "問題 {$id}", $expected);
    }

    /**
     * @param  array<string, list<RetrievedChunk>>  $chunks  問題 → 不套用門檻時的結果（依分數排序）
     */
    private function fakeRetriever(array $chunks, ?float $threshold = 0.6): void
    {
        $this->mock(RetrieverService::class, function (MockInterface $mock) use ($chunks, $threshold) {
            $mock->shouldReceive('configuredThreshold')->andReturn($threshold);
            $mock->shouldReceive('retrieve')->andReturnUsing(function (string $query, RetrieveOptions $options) use ($chunks) {
                $all = $chunks[$query] ?? [];
                $kept = $options->applyThreshold ? array_values(array_filter($all, fn (RetrievedChunk $c) => $c->score > $options->scoreThreshold)) : $all;

                return new RetrievalResult($kept, 'fake', $options->topK ?? 5, $options->applyThreshold ? $options->scoreThreshold : null);
            });
        });
    }

    private function evaluator(): RetrievalEvaluator
    {
        return $this->app->make(RetrievalEvaluator::class);
    }

    // ---- 命中判定 ----

    public function test_section_matches_last_segment_exactly(): void
    {
        $expected = new ExpectedSource('規章.pdf', '第7條');

        $this->assertSame(
            [true, false, false],
            [
                $expected->matches(self::chunk('規章.pdf', '第 3 章 附則 / 第7條', 0.5)),
                $expected->matches(self::chunk('規章.pdf', '第 3 章 附則 / 第7條之1', 0.5)),
                $expected->matches(self::chunk('其他.pdf', '第7條', 0.5)),
            ],
        );
    }

    public function test_null_section_matches_any_chunk_of_the_document(): void
    {
        $this->assertTrue((new ExpectedSource('公告.txt', null))->matches(self::chunk('公告.txt', null, 0.5)));
    }

    // ---- 評估 ----

    public function test_rank_and_hit_score_come_from_first_matching_chunk(): void
    {
        $this->fakeRetriever(['問題 q1' => [self::chunk('A.pdf', '第一條', 0.7), self::chunk('B.pdf', '第二條', 0.65), self::chunk('B.pdf', '第二條', 0.6)]]);

        $result = $this->evaluator()->evaluate([self::question('q1', QuestionType::Exact, [new ExpectedSource('B.pdf', '第二條')])])->results[0];

        $this->assertSame([2, 0.65, 0.7], [$result->rank, $result->hitScore, $result->top->score]);
    }

    public function test_recall_counts_only_answerable_questions_by_type(): void
    {
        $this->fakeRetriever([
            '問題 q1' => [self::chunk('A.pdf', '第一條', 0.7)],
            '問題 q2' => [self::chunk('X.pdf', null, 0.7), self::chunk('X.pdf', null, 0.69), self::chunk('A.pdf', '第二條', 0.68)],
            '問題 q3' => [self::chunk('X.pdf', null, 0.7)],
        ]);

        $report = $this->evaluator()->evaluate([
            self::question('q1', QuestionType::Exact, [new ExpectedSource('A.pdf', '第一條')]),
            self::question('q2', QuestionType::Paraphrase, [new ExpectedSource('A.pdf', '第二條')]),
            self::question('q3', QuestionType::NoAnswer),
        ]);

        $this->assertSame([0.5, 1.0, 1.0, 0.0, null], [
            $report->recall(1), $report->recall(3), $report->recall(1, QuestionType::Exact), $report->recall(1, QuestionType::Paraphrase), $report->recall(1, QuestionType::ExactId),
        ]);
    }

    public function test_threshold_errors_cover_both_directions(): void
    {
        $this->fakeRetriever([
            '問題 q1' => [self::chunk('A.pdf', '第一條', 0.55)],
            '問題 q2' => [self::chunk('A.pdf', '第一條', 0.7)],
            '問題 q3' => [self::chunk('A.pdf', '第一條', 0.65)],
            '問題 q4' => [self::chunk('A.pdf', '第一條', 0.5)],
        ]);

        $report = $this->evaluator()->evaluate([
            self::question('q1', QuestionType::Exact, [new ExpectedSource('A.pdf', '第一條')]),
            self::question('q2', QuestionType::Exact, [new ExpectedSource('A.pdf', '第一條')]),
            self::question('q3', QuestionType::NoAnswer),
            self::question('q4', QuestionType::NoAnswer),
        ]);

        $this->assertSame(['q1', 'q3'], array_map(fn ($r) => $r->question->id, $report->thresholdErrors()));
    }

    public function test_candidates_above_threshold_do_not_imply_correct_chunk_in_context(): void
    {
        // Top-1 是錯的條文且通過門檻，正確條文分數在門檻之下（Ch08 qwen3 的 q23）
        $this->fakeRetriever(['問題 q1' => [self::chunk('A.pdf', '第7條之1', 0.7), self::chunk('A.pdf', '第三條之一', 0.5)]]);

        $result = $this->evaluator()->evaluate([self::question('q1', QuestionType::ExactId, [new ExpectedSource('A.pdf', '第三條之一')])])->results[0];

        $this->assertSame([2, true, false], [$result->rank, $result->hasCandidates, $result->inContext]);
    }

    public function test_without_configured_threshold_only_recall_is_evaluated(): void
    {
        $this->fakeRetriever(['問題 q1' => [self::chunk('A.pdf', '第一條', 0.7)]], threshold: null);

        $report = $this->evaluator()->evaluate([self::question('q1', QuestionType::NoAnswer)]);

        $this->assertSame([null, null, null], [$report->scoreThreshold, $report->results[0]->hasCandidates, $report->results[0]->inContext]);
    }

    public function test_threshold_option_overrides_configured_threshold(): void
    {
        $this->fakeRetriever(['問題 q1' => [self::chunk('A.pdf', '第一條', 0.65)]]);

        $report = $this->evaluator()->evaluate([self::question('q1', QuestionType::NoAnswer)], scoreThreshold: 0.66);

        $this->assertSame([0.66, false], [$report->scoreThreshold, $report->results[0]->hasCandidates]);
    }

    public function test_mode_policy_and_threshold_are_passed_to_retriever(): void
    {
        $calls = [];
        $this->mock(RetrieverService::class, function (MockInterface $mock) use (&$calls) {
            $mock->shouldReceive('configuredThreshold')->andReturn(0.59);
            $mock->shouldReceive('retrieve')->andReturnUsing(function (string $query, RetrieveOptions $options) use (&$calls) {
                $calls[] = [$options->mode, $options->keywordOnlyPolicy, $options->applyThreshold, $options->topK];

                return new RetrievalResult([], 'fake', $options->topK ?? 5, 0.59, mode: $options->mode ?? RetrievalMode::Dense);
            });
        });

        $report = $this->evaluator()->evaluate([self::question('q1', QuestionType::NoAnswer)], mode: RetrievalMode::Hybrid, policy: KeywordOnlyPolicy::Allow, applyThreshold: true);

        $this->assertSame(
            [[RetrievalMode::Hybrid, KeywordOnlyPolicy::Allow, true, 20], [RetrievalMode::Hybrid, KeywordOnlyPolicy::Allow, true, null], RetrievalMode::Hybrid, true],
            [$calls[0], $calls[1], $report->mode, $report->thresholdApplied],
        );
    }

    // ---- 測試集 ----

    public function test_invalid_line_reports_line_number(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rag-set');
        file_put_contents($path, '{"id": "q01", "type": "exact", "question": "a", "expected": []}'."\n{壞掉");

        $this->expectExceptionObject(new InvalidArgumentException("{$path} 第 2 行不是有效的 JSON。"));
        $this->evaluator()->load($path);
    }

    public function test_project_test_set_is_valid(): void
    {
        $questions = $this->evaluator()->load(base_path('tests/rag-set/questions.jsonl'));
        $types = array_count_values(array_map(fn (TestQuestion $q) => $q->type->value, $questions));

        // 無答案題 expected 必須為空，有答案題必須至少一個預期來源
        $this->assertSame(
            [30, ['exact' => 7, 'paraphrase' => 12, 'exact_id' => 5, 'no_answer' => 6], []],
            [count($questions), $types, array_values(array_filter($questions, fn (TestQuestion $q) => ($q->type === QuestionType::NoAnswer) !== ($q->expected === [])))],
        );
    }

    // ---- 指令 ----

    public function test_eval_command_writes_markdown_report(): void
    {
        $this->fakeRetriever(['問題 q1' => [self::chunk('A.pdf', '第一條', 0.7)]]);
        $set = tempnam(sys_get_temp_dir(), 'rag-set');
        file_put_contents($set, json_encode(['id' => 'q1', 'type' => 'exact', 'question' => '問題 q1', 'expected' => [['document' => 'A.pdf', 'section' => '第一條']]]));
        $dir = sys_get_temp_dir().'/rag-eval-'.uniqid();

        $this->artisan('rag:eval', ['--set' => $set, '--output-dir' => $dir])->assertSuccessful();

        $this->assertStringContainsString('| 全部（1 題） | 100% | 100% | 100% | 100% |', File::get(File::files($dir)[0]->getPathname()));
        File::deleteDirectory($dir);
    }
}
