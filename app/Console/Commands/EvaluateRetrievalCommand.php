<?php

namespace App\Console\Commands;

use App\Rag\Evaluation\EvaluationReport;
use App\Rag\Evaluation\QuestionResult;
use App\Rag\Evaluation\QuestionType;
use App\Rag\Evaluation\RetrievalEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 以測試集評估檢索品質，結果同時寫入 docs/notes/（檔名含 Collection 與時間，方便比較）。
 */
class EvaluateRetrievalCommand extends Command
{
    protected $signature = 'rag:eval
        {--model= : Embedding 模型，未填使用目前設定的模型}
        {--threshold= : 覆寫相關度門檻（試算其他門檻用）}
        {--set=tests/rag-set/questions.jsonl : 測試集}
        {--output-dir=docs/notes : 評估結果的存放目錄}';

    protected $description = '以測試集計算 Recall@K、Top-1 分數分布與門檻判斷結果';

    public function handle(RetrievalEvaluator $evaluator): int
    {
        $report = $evaluator->evaluate(
            $evaluator->load($this->path($this->option('set'))),
            $this->option('model'),
            $this->option('threshold') === null ? null : (float) $this->option('threshold'),
        );

        $sections = $this->sections($report);
        foreach ($sections as $title => [$headers, $rows]) {
            $this->info($title);
            $this->table($headers, $rows);
        }

        $path = $this->path($this->option('output-dir')).'/rag-eval-'.$report->collection.'-'.now()->format('Ymd-His').'.md';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->markdown($report, $sections));
        $this->line("結果已寫入 {$path}");

        return self::SUCCESS;
    }

    /** 相對路徑以專案根目錄為準 */
    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /** @return array<string, array{list<string>, list<list<string|int>>}> 標題 → [欄位, 列] */
    private function sections(EvaluationReport $report): array
    {
        $percent = fn (?float $v) => $v === null ? '—' : sprintf('%.0f%%', $v * 100);
        $recallRow = fn (string $label, ?QuestionType $type) => [$label, ...array_map(fn (int $k) => $percent($report->recall($k, $type)), RetrievalEvaluator::RECALL_AT)];

        $distribution = function (string $label, array $results) {
            $scores = EvaluationReport::topScores($results);

            return $scores === []
                ? [$label, count($results), '—', '—', '—']
                : [$label, count($results), sprintf('%.4f', min($scores)), sprintf('%.4f', array_sum($scores) / count($scores)), sprintf('%.4f', max($scores))];
        };

        $sections = [
            'Recall@K（不套用門檻，只計算有答案的題目）' => [
                ['題型', ...array_map(fn (int $k) => "@{$k}", RetrievalEvaluator::RECALL_AT)],
                [
                    $recallRow('全部（'.count($report->answerable()).' 題）', null),
                    ...array_map(fn (QuestionType $t) => $recallRow($t->label(), $t), [QuestionType::Exact, QuestionType::Paraphrase, QuestionType::ExactId]),
                ],
            ],
            'Top-1 分數分布' => [
                ['', '題數', '最小', '平均', '最大'],
                [$distribution('有答案題', $report->answerable()), $distribution('無答案題', $report->noAnswer())],
            ],
        ];

        if ($report->scoreThreshold !== null) {
            $rejected = count(array_filter($report->noAnswer(), fn (QuestionResult $r) => $r->hasCandidates === false));
            $passed = count(array_filter($report->answerable(), fn (QuestionResult $r) => $r->hasCandidates === true));
            $inContext = count(array_filter($report->answerable(), fn (QuestionResult $r) => $r->inContext === true));
            $sections["套用門檻（分數 > {$report->scoreThreshold}）"] = [
                ['', '正確', '錯誤'],
                [
                    ['無答案題回傳「無候選」', $rejected, count($report->noAnswer()) - $rejected],
                    ['有答案題通過門檻', $passed, count($report->answerable()) - $passed],
                    ["有答案題的正確段落進入 Context（Top-{$report->contextTopK}）", $inContext, count($report->answerable()) - $inContext],
                ],
            ];
        }

        $sections['每題明細'] = [
            ['id', '題型', '問題', '命中名次', '命中分數', 'Top-1 分數', 'Top-1 來源', '門檻後', '進入 Context'],
            array_map(fn (QuestionResult $r) => [
                $r->question->id,
                $r->question->type->label(),
                $r->question->question,
                $r->question->type === QuestionType::NoAnswer ? '—' : ($r->rank ?? '未命中'),
                $r->hitScore === null ? '—' : sprintf('%.4f', $r->hitScore),
                $r->top === null ? '—' : sprintf('%.4f', $r->top->score),
                $r->top === null ? '—' : $r->top->documentName.' '.($r->top->section ?? ''),
                match ($r->hasCandidates) {
                    null => '—',
                    true => in_array($r, $report->thresholdErrors(), true) ? '有候選 ✗' : '有候選',
                    false => in_array($r, $report->thresholdErrors(), true) ? '無候選 ✗' : '無候選',
                },
                $r->question->type === QuestionType::NoAnswer || $r->inContext === null ? '—' : ($r->inContext ? '是' : '否'),
            ], $report->results),
        ];

        return $sections;
    }

    /** @param array<string, array{list<string>, list<list<string|int>>}> $sections */
    private function markdown(EvaluationReport $report, array $sections): string
    {
        $lines = [
            "# 檢索評估：{$report->collection}",
            '',
            '- 時間：'.now()->format('Y-m-d H:i'),
            "- 模型：{$report->model}",
            '- 測試集：'.$this->option('set').'（'.count($report->results).' 題）',
            '- 門檻：'.($report->scoreThreshold === null ? '未設定' : "> {$report->scoreThreshold}"),
        ];

        foreach ($sections as $title => [$headers, $rows]) {
            $lines[] = '';
            $lines[] = "## {$title}";
            $lines[] = '';
            $lines[] = '| '.implode(' | ', $headers).' |';
            $lines[] = '|'.str_repeat(' --- |', count($headers));
            foreach ($rows as $row) {
                $lines[] = '| '.implode(' | ', array_map(fn ($cell) => str_replace('|', '\|', (string) $cell), $row)).' |';
            }
        }

        return implode("\n", $lines)."\n";
    }
}
