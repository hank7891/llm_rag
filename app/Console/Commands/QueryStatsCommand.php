<?php

namespace App\Console\Commands;

use App\Rag\Answer\QuerySource;
use App\Rag\Stats\QueryLogStats;
use App\Repositories\RagQueryLogRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * rag_query_logs 的統計：改寫與 Reranker 的降級率、回答狀態分布、各階段耗時 p50 / p95。
 */
class QueryStatsCommand extends Command
{
    protected $signature = 'rag:stats
        {--since= : 起始時間（含），例如 2026-10-09 或 "7 days ago"}
        {--until= : 結束時間（不含）}
        {--include-eval : 一併統計評估指令（source = eval）的提問}';

    protected $description = '統計問答紀錄：改寫 fallback 與 Rerank 降級率、回答狀態分布、各階段耗時 p50 / p95';

    public function handle(RagQueryLogRepository $logs): int
    {
        $since = $this->option('since') === null ? null : Carbon::parse($this->option('since'));
        $until = $this->option('until') === null ? null : Carbon::parse($this->option('until'));
        $stats = new QueryLogStats($logs->between($since, $until, $this->option('include-eval') ? [] : [QuerySource::Eval]));

        $this->line(sprintf('期間：%s ～ %s；%s；共 %d 筆', $since?->format('Y-m-d H:i') ?? '最早', $until?->format('Y-m-d H:i') ?? '現在', $this->option('include-eval') ? '含評估提問' : '不含評估提問（source = eval）', $stats->count()));
        if ($stats->count() === 0) {
            return self::SUCCESS;
        }

        $percent = fn (int $n, int $total) => $total === 0 ? '—' : sprintf('%.1f%%', $n / $total * 100);
        $rate = fn (?float $r) => $r === null ? '—' : sprintf('%.1f%%', $r * 100);

        $this->info('回答狀態');
        $this->table(['狀態', '筆數', '比例'], collect($stats->statuses())->map(fn (int $n, string $status) => [$status, $n, $percent($n, $stats->count())])->values()->all());

        $rewrite = $stats->rewriteStatuses();
        $this->info('改寫（Ch13 以前的紀錄沒有改寫狀態）');
        $this->table(['狀態', '筆數'], [...collect($rewrite)->map(fn (int $n, string $status) => [$status, $n])->values()->all(), ['fallback 比例（÷ 有嘗試改寫的筆數）', $rate($stats->rewriteFallbackRate())]]);

        $rerank = $stats->rerank();
        $this->info('Reranker（Ch14 起才有紀錄）');
        $this->table(['項目', '數值'], [['有嘗試重排的筆數', $rerank['attempted']], ['降級筆數', $rerank['degraded']], ['降級比例', $rate($rerank['rate'])]]);

        $this->info('各階段耗時（毫秒）');
        $this->table(['階段', '筆數', 'p50', 'p95'], collect($stats->latencies())->map(fn (array $l, string $key) => [QueryLogStats::STAGES[$key], $l['count'], $l['p50'] ?? '—', $l['p95'] ?? '—'])->values()->all());

        return self::SUCCESS;
    }
}
