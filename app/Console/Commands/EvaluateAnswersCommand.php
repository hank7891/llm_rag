<?php

namespace App\Console\Commands;

use App\Ai\Chat\DTO\FinishReason;
use App\Rag\Evaluation\AnswerEvaluator;
use App\Rag\Evaluation\AnswerResult;
use App\Rag\Evaluation\QuestionType;
use App\Rag\Evaluation\RetrievalEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 以測試集逐題執行完整 RAG，結果寫入 docs/notes/ch09-answers-{provider}[-{label}].md，保留「人工判讀」欄位。
 */
class EvaluateAnswersCommand extends Command
{
    protected $signature = 'rag:eval-answer
        {--provider= : Chat Provider，未填使用預設值}
        {--set=tests/rag-set/questions.jsonl : 測試集}
        {--output-dir=docs/notes : 結果的存放目錄}
        {--label= : 加在檔名後面，區分同一個 Provider 的不同設定（例如 reasoning-think）}';

    protected $description = '以測試集逐題執行 RAG 問答，自動檢查資料不足判斷與 [n] 標示，答案正確性留給人工判讀';

    public function handle(RetrievalEvaluator $questions, AnswerEvaluator $evaluator): int
    {
        $results = [];
        $set = $questions->load($this->path($this->option('set')));
        $this->withProgressBar($set, function ($question) use ($evaluator, &$results) {
            array_push($results, ...$evaluator->evaluate([$question], $this->option('provider')));
        });
        $this->newLine(2);

        $provider = collect($results)->first(fn (AnswerResult $r) => $r->answer !== null)?->answer->provider ?? $this->option('provider') ?? '—';
        $summary = $this->summary($results);
        $this->table(['項目', '結果'], $summary);

        $label = $this->option('label') === null ? '' : '-'.$this->option('label');
        $path = $this->path($this->option('output-dir'))."/ch09-answers-{$provider}{$label}.md";
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->markdown($provider, $results, $summary));
        $this->line("結果已寫入 {$path}");

        return self::SUCCESS;
    }

    /** 相對路徑以專案根目錄為準 */
    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /**
     * @param  list<AnswerResult>  $results
     * @return list<array{string, string}>
     */
    private function summary(array $results): array
    {
        $noAnswer = array_filter($results, fn (AnswerResult $r) => $r->question->type === QuestionType::NoAnswer);
        $answerable = array_filter($results, fn (AnswerResult $r) => $r->question->type !== QuestionType::NoAnswer);
        $answered = array_filter($results, fn (AnswerResult $r) => $r->answer !== null);
        $called = array_filter($answered, fn (AnswerResult $r) => $r->answer->llmCalled);
        $count = fn (array $items, callable $test) => count(array_filter($items, $test));
        $avg = fn (array $items, callable $value) => $items === [] ? '—' : sprintf('%.0f', array_sum(array_map($value, $items)) / count($items));

        return [
            ['無答案題回答資料不足', $count($noAnswer, fn (AnswerResult $r) => $r->passed()).' / '.count($noAnswer)],
            ['有答案題：未答資料不足且標示 [n]', $count($answerable, fn (AnswerResult $r) => $r->passed()).' / '.count($answerable)],
            ['有答案題：門檻擋下（未呼叫 LLM）', (string) $count($answerable, fn (AnswerResult $r) => $r->answer !== null && ! $r->answer->llmCalled)],
            ['有答案題：LLM 回答資料不足', (string) $count($answerable, fn (AnswerResult $r) => $r->answer?->llmCalled && $r->answer->status->isInsufficient())],
            ['輸出被截斷（finish_reason = length）', (string) $count($called, fn (AnswerResult $r) => $r->answer->finishReason === FinishReason::Length)],
            ['呼叫失敗（逾時等，未重試）', (string) $count($results, fn (AnswerResult $r) => $r->error !== null)],
            ['呼叫 LLM 的題數', count($called).' / '.count($results)],
            ['[n] 標示率（呼叫 LLM 且未答資料不足）', $this->citationRate($called)],
            ['平均輸入 / 輸出 Token（呼叫 LLM 的題目）', $avg($called, fn (AnswerResult $r) => $r->answer->usage->inputTokens).' / '.$avg($called, fn (AnswerResult $r) => $r->answer->usage->outputTokens)],
            ['平均耗時：檢索 / LLM（ms）', $avg($answered, fn (AnswerResult $r) => $r->answer->retrievalMs).' / '.$avg($called, fn (AnswerResult $r) => $r->answer->llmMs)],
        ];
    }

    /** @param array<AnswerResult> $called */
    private function citationRate(array $called): string
    {
        $answered = array_filter($called, fn (AnswerResult $r) => ! $r->answer->status->isInsufficient());

        return $answered === [] ? '—' : sprintf('%d / %d', count(array_filter($answered, fn (AnswerResult $r) => $r->citations !== [])), count($answered));
    }

    /**
     * @param  list<AnswerResult>  $results
     * @param  list<array{string, string}>  $summary
     */
    private function markdown(string $provider, array $results, array $summary): string
    {
        $cell = fn (string $text) => str_replace(['|', "\n"], ['\|', '<br>'], $text);
        $model = collect($results)->first(fn (AnswerResult $r) => $r->answer?->model !== null)?->answer->model ?? '—';
        $retrieval = collect($results)->first(fn (AnswerResult $r) => $r->answer !== null)?->answer->retrieval;
        $withExpected = collect($results)->contains(fn (AnswerResult $r) => $r->question->expectedAnswer !== null);

        $lines = [
            "# Ch09 端到端問答：{$provider}",
            '',
            '- 時間：'.now()->format('Y-m-d H:i'),
            "- Provider / 模型：{$provider} / {$model}",
            '- 檢索：'.($retrieval === null ? '—' : "{$retrieval->model}，Top-K {$retrieval->topK}，門檻 > {$retrieval->scoreThreshold}"),
            '- 測試集：'.$this->option('set').'（'.count($results).' 題）',
            '- 自動檢查：無答案題須回答資料不足；有答案題須未答資料不足且標示至少一個 [n]。**答案是否正確請在「人工判讀」欄填寫。**',
            '',
            '## 摘要',
            '',
            '| 項目 | 結果 |',
            '| --- | --- |',
            ...array_map(fn (array $row) => "| {$row[0]} | {$row[1]} |", $summary),
            '',
            '## 每題結果',
            '',
            '| id | 題型 | 問題 |'.($withExpected ? ' 預期答案與判讀標準 |' : '').' status | LLM | Top-1 | 回答 | [n] | 自動檢查 | Token（入/出） | 結束原因 | LLM ms | 人工判讀 |',
            '|'.str_repeat(' --- |', $withExpected ? 14 : 13),
        ];

        foreach ($results as $r) {
            $a = $r->answer;
            $lines[] = '| '.implode(' | ', [
                $r->question->id,
                $r->question->type->label(),
                $cell($r->question->question),
                ...($withExpected ? [$cell($r->question->expectedAnswer ?? '')] : []),
                $a?->status->value ?? '錯誤',
                $a === null ? '—' : ($a->llmCalled ? '是' : '否'),
                $a?->retrieval->topScore() === null ? '—' : sprintf('%.4f', $a->retrieval->topScore()),
                $cell($a?->answer ?? "（{$r->error}）"),
                $r->citations === [] ? '—' : implode(',', $r->citations),
                $r->passed() ? '✓' : '✗',
                $a?->usage === null ? '—' : "{$a->usage->inputTokens}/{$a->usage->outputTokens}",
                $a?->finishReason->value ?? '—',
                $a?->llmMs ?? '—',
                '',
            ]).' |';
        }

        return implode("\n", $lines)."\n";
    }
}
