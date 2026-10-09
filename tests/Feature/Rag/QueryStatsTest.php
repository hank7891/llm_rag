<?php

namespace Tests\Feature\Rag;

use App\Models\RagQueryLog;
use App\Rag\Answer\AnswerStatus;
use App\Rag\Answer\QuerySource;
use App\Rag\Conversation\RewriteStatus;
use App\Rag\Stats\QueryLogStats;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * rag:stats 的降級率與 p50 / p95（Ch14），以固定的紀錄驗證計算方式。
 */
class QueryStatsTest extends TestCase
{
    use DatabaseTransactions;

    private static function log(array $attributes): RagQueryLog
    {
        return new RagQueryLog([
            'question' => 'q', 'status_key' => AnswerStatus::Answered, 'source_key' => QuerySource::Api, 'llm_called' => true,
            'retrieval_ms' => 100, 'reranked' => false, 'rerank_degraded' => false, 'created_at' => now(), ...$attributes,
        ]);
    }

    /** 10 筆：改寫 4 筆嘗試（1 筆 fallback）；重排 5 筆嘗試（1 筆降級）；回答耗時 1000～10000 */
    private static function stats(): QueryLogStats
    {
        $logs = [];
        foreach (range(1, 10) as $i) {
            $logs[] = self::log([
                'status_key' => $i <= 7 ? AnswerStatus::Answered : ($i <= 9 ? AnswerStatus::InsufficientNoCandidates : AnswerStatus::Interrupted),
                'rewrite_status_key' => match (true) {
                    $i <= 2 => RewriteStatus::Rewritten, $i === 3 => RewriteStatus::Unchanged, $i === 4 => RewriteStatus::Fallback, default => RewriteStatus::Skipped
                },
                'rewrite_ms' => $i <= 4 ? $i * 1000 : null,
                'reranked' => $i <= 4,
                'rerank_degraded' => $i === 5,
                'rerank_ms' => $i <= 4 ? 200 : null,
                'llm_ms' => $i <= 7 || $i === 10 ? $i * 1000 : null,
            ]);
        }

        return new QueryLogStats($logs);
    }

    public function test_status_and_rewrite_distribution(): void
    {
        $stats = self::stats();

        $this->assertSame(
            [['answered' => 7, 'insufficient_no_candidates' => 2, 'insufficient_by_llm' => 0, 'interrupted' => 1], ['skipped' => 6, 'rewritten' => 2, 'unchanged' => 1, 'fallback' => 1], 0.25],
            [$stats->statuses(), $stats->rewriteStatuses(), $stats->rewriteFallbackRate()],
        );
    }

    public function test_rerank_degradation_rate_counts_only_attempts(): void
    {
        $this->assertSame(['attempted' => 5, 'degraded' => 1, 'rate' => 0.2], self::stats()->rerank());
    }

    public function test_latency_percentiles_skip_missing_stages(): void
    {
        $latencies = self::stats()->latencies();

        $this->assertSame(
            [['count' => 4, 'p50' => 2000, 'p95' => 4000], ['count' => 8, 'p50' => 4000, 'p95' => 10000], ['count' => 4, 'p50' => 200, 'p95' => 200]],
            [$latencies['rewrite_ms'], $latencies['llm_ms'], $latencies['rerank_ms']],
        );
    }

    public function test_no_attempts_gives_no_rate(): void
    {
        $stats = new QueryLogStats([self::log(['rewrite_status_key' => RewriteStatus::Skipped])]);

        $this->assertSame([null, null], [$stats->rewriteFallbackRate(), $stats->rerank()['rate']]);
    }

    public function test_command_excludes_eval_queries_by_default(): void
    {
        RagQueryLog::query()->delete();
        self::log(['source_key' => QuerySource::Api, 'embedding_model' => 'fake', 'provider' => 'fake', 'passed_count' => 1, 'citation_count' => 0, 'invalid_ref_count' => 0, 'uncited' => false])->save();
        self::log(['source_key' => QuerySource::Eval, 'embedding_model' => 'fake', 'provider' => 'fake', 'passed_count' => 1, 'citation_count' => 0, 'invalid_ref_count' => 0, 'uncited' => false])->save();

        $this->artisan('rag:stats')->expectsOutputToContain('共 1 筆')->assertSuccessful();
        $this->artisan('rag:stats', ['--include-eval' => true])->expectsOutputToContain('共 2 筆')->assertSuccessful();
    }
}
