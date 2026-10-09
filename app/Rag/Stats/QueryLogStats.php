<?php

namespace App\Rag\Stats;

use App\Models\RagQueryLog;
use App\Rag\Answer\AnswerStatus;
use App\Rag\Conversation\RewriteStatus;

/**
 * rag_query_logs 的降級率、狀態分布與各階段耗時（Ch14）。只做統計，不做告警。
 * 改寫與 Reranker 失敗都會靜默降級（問答照常完成），不統計就看不出品質正在下滑。
 */
final readonly class QueryLogStats
{
    /** 各階段耗時欄位；total = 改寫 + 檢索（含重排）+ 回答 */
    public const STAGES = ['rewrite_ms' => '改寫', 'retrieval_ms' => '檢索（含重排）', 'rerank_ms' => '重排', 'llm_ms' => '回答', 'total' => '總計'];

    /** @param list<RagQueryLog> $logs */
    public function __construct(private array $logs) {}

    public function count(): int
    {
        return count($this->logs);
    }

    /** @return array<string, int> 回答狀態 → 筆數 */
    public function statuses(): array
    {
        return $this->distribution(AnswerStatus::cases(), fn (RagQueryLog $log) => $log->status_key);
    }

    /** @return array<string, int> 改寫狀態 → 筆數（Ch13 以前的紀錄沒有改寫狀態，不計入） */
    public function rewriteStatuses(): array
    {
        return $this->distribution(RewriteStatus::cases(), fn (RagQueryLog $log) => $log->rewrite_status_key);
    }

    /** 改寫降級率：fallback ÷ 有嘗試改寫的筆數（skipped 是第一輪，不算嘗試） */
    public function rewriteFallbackRate(): ?float
    {
        $statuses = $this->rewriteStatuses();
        $attempted = $statuses['rewritten'] + $statuses['unchanged'] + $statuses['fallback'];

        return $attempted === 0 ? null : $statuses['fallback'] / $attempted;
    }

    /** @return array{attempted: int, degraded: int, rate: ?float} Reranker 降級率：降級 ÷ 有嘗試重排的筆數（Ch14 起才有紀錄） */
    public function rerank(): array
    {
        $reranked = count(array_filter($this->logs, fn (RagQueryLog $log) => $log->reranked));
        $degraded = count(array_filter($this->logs, fn (RagQueryLog $log) => $log->rerank_degraded));
        $attempted = $reranked + $degraded;

        return ['attempted' => $attempted, 'degraded' => $degraded, 'rate' => $attempted === 0 ? null : $degraded / $attempted];
    }

    /** @return array<string, array{count: int, p50: ?int, p95: ?int}> 各階段耗時（毫秒）；沒有該階段的紀錄不計入 */
    public function latencies(): array
    {
        $values = [
            'rewrite_ms' => array_map(fn (RagQueryLog $log) => $log->rewrite_ms, $this->logs),
            'retrieval_ms' => array_map(fn (RagQueryLog $log) => $log->retrieval_ms, $this->logs),
            'rerank_ms' => array_map(fn (RagQueryLog $log) => $log->rerank_ms, $this->logs),
            'llm_ms' => array_map(fn (RagQueryLog $log) => $log->llm_ms, $this->logs),
            'total' => array_map(fn (RagQueryLog $log) => ($log->rewrite_ms ?? 0) + $log->retrieval_ms + ($log->llm_ms ?? 0), $this->logs),
        ];

        return array_map(function (array $stage) {
            $stage = array_values(array_filter($stage, fn (?int $v) => $v !== null));
            sort($stage);

            return ['count' => count($stage), 'p50' => self::percentile($stage, 50), 'p95' => self::percentile($stage, 95)];
        }, $values);
    }

    /** Nearest-rank：排序後第 ceil(p% × n) 個值（與 rag:eval 的延遲統計相同） */
    public static function percentile(array $sorted, int $p): ?int
    {
        return $sorted === [] ? null : $sorted[max(0, (int) ceil($p / 100 * count($sorted)) - 1)];
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @param  callable(RagQueryLog): ?\BackedEnum  $value
     * @return array<string, int>
     */
    private function distribution(array $cases, callable $value): array
    {
        $counts = array_fill_keys(array_map(fn (\BackedEnum $case) => $case->value, $cases), 0);
        foreach ($this->logs as $log) {
            $case = $value($log);
            if ($case !== null) {
                $counts[$case->value]++;
            }
        }

        return $counts;
    }
}
