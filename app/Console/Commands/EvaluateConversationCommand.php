<?php

namespace App\Console\Commands;

use App\Ai\Chat\DTO\FinishReason;
use App\Rag\Conversation\QueryRewriter;
use App\Rag\Conversation\RewriteStatus;
use App\Rag\Evaluation\ConversationEvaluator;
use App\Rag\Evaluation\ConversationResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 多輪檢索評估（Ch13）：以腳本化歷史比較 none / concat / llm 三種檢索用問題，結果寫入 docs/notes/。
 */
class EvaluateConversationCommand extends Command
{
    protected $signature = 'rag:eval-conversation
        {--rewrite=llm : none（只用追問）/ concat（串接歷史問題）/ llm（Query Rewriting）}
        {--window= : 保留最近幾輪，未填使用 rag.conversation.window_turns}
        {--rewrite-provider= : 改寫用的 Chat Provider，未填使用設定值（follow 時跟隨 Chat 的預設 Provider）}
        {--prompt-version= : 改寫 Prompt 版本（v1 / v2 / v3），未填使用設定值}
        {--rewrite-think= : on / off：覆寫 rag.conversation.rewrite.providers.ollama.provider_options.think}
        {--rewrite-timeout= : 覆寫本次改寫 Provider 的逾時秒數}
        {--rerank= : on / off，未填使用 rag.rerank.enabled}
        {--answer : 也執行完整問答，記錄回答 LLM 的 input_tokens}
        {--set=tests/rag-set/conversations.jsonl : 多輪測試集}
        {--label= : 加在檔名後面}
        {--output-dir=docs/notes : 結果的存放目錄}';

    protected $description = '以多輪測試集比較追問的檢索結果：不改寫、串接歷史、LLM 改寫';

    public function handle(): int
    {
        $configured = config('rag.conversation.rewrite.provider');
        $provider = $this->option('rewrite-provider') ?? ($configured === QueryRewriter::FOLLOW ? config('llm.chat.default') : $configured);

        // 改寫參數在 QueryRewriter 建立時注入，所以先改設定再從 Container 取得 Evaluator
        if ($this->option('rewrite-think') !== null) {
            config()->set('rag.conversation.rewrite.providers.ollama.provider_options.think', $this->option('rewrite-think') === 'on');
        }
        if ($this->option('rewrite-timeout') !== null) {
            config()->set("rag.conversation.rewrite.providers.{$provider}.timeout", (int) $this->option('rewrite-timeout'));
        }
        $evaluator = app(ConversationEvaluator::class);

        $mode = $this->option('rewrite');
        $rerank = $this->option('rerank') === null ? (bool) config('rag.rerank.enabled') : $this->option('rerank') === 'on';
        $window = $this->option('window') === null ? (int) config('rag.conversation.window_turns') : (int) $this->option('window');
        $version = $this->option('prompt-version') ?? config('rag.conversation.rewrite.prompt_version');

        $results = $evaluator->evaluate($evaluator->load($this->path($this->option('set'))), $mode, $window, $this->option('rewrite-provider'), $this->option('prompt-version'), $rerank, (bool) $this->option('answer'));

        $settings = [
            "檢索用問題：{$mode}".($mode === 'llm' ? "（改寫 Provider：{$provider}（設定值 {$configured}），Prompt：{$version}，改寫設定：".json_encode(config("rag.conversation.rewrite.providers.{$provider}")).'）' : ''),
            "Window：{$window} 輪，歷史預算 ".config('rag.conversation.history_budget_chars').' 字元',
            '檢索：'.config('rag.retrieval.mode').'（'.config('rag.retrieval.keyword_only_policy').'），Reranker：'.($rerank ? 'on（候選 '.config('rag.rerank.candidates').'、keep_exact '.(config('rag.rerank.keep_exact') ? 'on' : 'off').'、prefix_metadata '.(config('rag.rerank.prefix_metadata') ? 'on' : 'off').'）' : 'off'),
        ];
        $sections = $this->sections($results);

        foreach ($settings as $line) {
            $this->line($line);
        }
        foreach ($sections as $title => [$headers, $rows]) {
            $this->info($title);
            $this->table($headers, $rows);
        }

        $label = $this->option('label') === null ? '' : '-'.$this->option('label');
        $path = $this->path($this->option('output-dir'))."/ch13-eval-{$mode}{$label}-".now()->format('Ymd-His').'.md';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->markdown($settings, $sections));
        $this->line("結果已寫入 {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  list<ConversationResult>  $results
     * @return array<string, array{list<string>, list<list<string|int>>}>
     */
    private function sections(array $results): array
    {
        $answerable = array_values(array_filter($results, fn (ConversationResult $r) => ! $r->isNoAnswer() && ! $r->isAmbiguous()));
        $percent = fn (array $items, int $k) => $items === [] ? '—' : sprintf('%.0f%%', count(array_filter($items, fn (ConversationResult $r) => $r->rank !== null && $r->rank <= $k)) / count($items) * 100);
        $mrr = fn (array $items) => $items === [] ? '—' : sprintf('%.3f', array_sum(array_map(fn (ConversationResult $r) => $r->rank === null ? 0 : 1 / $r->rank, $items)) / count($items));
        $row = fn (string $label, array $items) => [$label, count($items), $percent($items, 1), $percent($items, 5), $mrr($items)];

        $types = array_values(array_unique(array_map(fn (ConversationResult $r) => $r->case->type, $answerable)));
        $rewriteMs = array_values(array_filter(array_map(fn (ConversationResult $r) => $r->rewrite?->latencyMs, $results), fn (?int $v) => $v !== null));
        sort($rewriteMs);
        $pct = fn (array $v, int $p) => $v === [] ? '—' : $v[max(0, (int) ceil($p / 100 * count($v)) - 1)];
        $rewriteTokens = array_values(array_filter(array_map(fn (ConversationResult $r) => $r->rewrite?->usage === null ? null : $r->rewrite->usage->inputTokens + $r->rewrite->usage->outputTokens, $results), fn (?int $v) => $v !== null));
        $inputTokens = array_values(array_filter(array_map(fn (ConversationResult $r) => $r->answerInputTokens, $results), fn (?int $v) => $v !== null));

        return [
            'Recall（有答案題，照系統實際行為：Dense 門檻與資料不足的判斷）' => [
                ['題型', '題數', '@1', '@5', 'MRR'],
                [$row('全部', $answerable), ...array_map(fn (string $t) => $row($t, array_values(array_filter($answerable, fn (ConversationResult $r) => $r->case->type === $t))), $types)],
            ],
            '無答案題（分開列出）' => [
                ['id', '追問', '檢索用問題', '結果', 'Dense 最高分'],
                array_map(fn (ConversationResult $r) => [$r->case->id, $r->case->question, $r->query, $r->retrieval->hasCandidates() ? '有候選（交給回答 LLM 判斷）' : '無候選（不呼叫回答 LLM）', $r->retrieval->denseTopScore === null ? '—' : sprintf('%.4f', $r->retrieval->denseTopScore)], array_values(array_filter($results, fn (ConversationResult $r) => $r->isNoAnswer()))),
            ],
            '歧義題（分開列出，不計入上表）' => [
                ['id', '追問', '檢索用問題', '改寫狀態', '命中名次'],
                array_map(fn (ConversationResult $r) => [$r->case->id, $r->case->question, $r->query, $r->rewrite?->status->value ?? '—', $r->rank ?? '未命中'], array_values(array_filter($results, fn (ConversationResult $r) => $r->isAmbiguous()))),
            ],
            '成本' => [
                ['項目', '數值'],
                [
                    ['呼叫改寫 LLM 的題數', count(array_filter($results, fn (ConversationResult $r) => $r->rewrite?->called))],
                    ['改寫結果與原問題相同（unchanged）', count(array_filter($results, fn (ConversationResult $r) => $r->rewrite?->status === RewriteStatus::Unchanged))],
                    ['改寫降級次數', count(array_filter($results, fn (ConversationResult $r) => $r->rewrite?->status === RewriteStatus::Fallback))],
                    ['因 history_budget_chars 少保留輪數的題數', count(array_filter($results, fn (ConversationResult $r) => $r->keptTurns < $r->inWindowTurns))],
                    ['改寫耗時 p50 / p95（ms）', $pct($rewriteMs, 50).' / '.$pct($rewriteMs, 95)],
                    ['檢索耗時平均（ms）', (int) round(array_sum(array_map(fn (ConversationResult $r) => $r->retrievalMs, $results)) / max(1, count($results)))],
                    ['改寫結束原因 length 的題數', count(array_filter($results, fn (ConversationResult $r) => $r->rewrite?->finishReason === FinishReason::Length))],
                    ['改寫 prompt + output Token 最大值', $rewriteTokens === [] ? '—' : max($rewriteTokens)],
                    ['回答 LLM 最大 input_tokens（--answer）', $inputTokens === [] ? '—' : max($inputTokens).'（num_ctx 8192）'],
                ],
            ],
            '每題明細' => [
                ['id', '題型', '追問', '檢索用問題', '改寫狀態', '改寫 ms', '改寫結束原因', '改寫 Token（prompt / output）', '保留輪數', '命中名次', 'Top-1 來源', '預期改寫'],
                array_map(fn (ConversationResult $r) => [
                    $r->case->id,
                    $r->case->type,
                    $r->case->question,
                    str_replace("\n", ' ', $r->query),
                    $r->rewrite?->status->value ?? '—',
                    $r->rewrite?->latencyMs ?? '—',
                    $r->rewrite?->finishReason->value ?? '—',
                    $r->rewrite?->usage === null ? '—' : "{$r->rewrite->usage->inputTokens} / {$r->rewrite->usage->outputTokens}",
                    "{$r->keptTurns}/{$r->inWindowTurns}",
                    $r->isNoAnswer() ? ($r->retrieval->hasCandidates() ? '有候選' : '無候選') : ($r->rank ?? '未命中'),
                    ($r->retrieval->chunks[0] ?? null) === null ? '—' : $r->retrieval->chunks[0]->documentName.' '.($r->retrieval->chunks[0]->section ?? ''),
                    $r->case->expectedRewrite ?? '',
                ], $results),
            ],
        ];
    }

    /** 相對路徑以專案根目錄為準 */
    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /**
     * @param  list<string>  $settings
     * @param  array<string, array{list<string>, list<list<string|int>>}>  $sections
     */
    private function markdown(array $settings, array $sections): string
    {
        $lines = ['# Ch13 多輪檢索評估', '', '- 時間：'.now()->format('Y-m-d H:i'), ...array_map(fn (string $s) => "- {$s}", $settings)];
        foreach ($sections as $title => [$headers, $rows]) {
            array_push($lines, '', "## {$title}", '', '| '.implode(' | ', $headers).' |', '|'.str_repeat(' --- |', count($headers)));
            foreach ($rows as $row) {
                $lines[] = '| '.implode(' | ', array_map(fn ($c) => str_replace(['|', "\n"], ['\|', ' '], (string) $c), $row)).' |';
            }
        }

        return implode("\n", $lines)."\n";
    }
}
