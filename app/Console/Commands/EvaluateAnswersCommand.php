<?php

namespace App\Console\Commands;

use App\Ai\Chat\DTO\FinishReason;
use App\Rag\Answer\AnswerStatus;
use App\Rag\Citation\InvalidReason;
use App\Rag\Citation\InvalidRef;
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
        {--label= : 加在檔名後面，區分同一個 Provider 的不同設定（例如 reasoning-think）}
        {--prefix=ch09-answers : 檔名前綴}';

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
        $path = $this->path($this->option('output-dir'))."/{$this->option('prefix')}-{$provider}{$label}.md";
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
            ...$this->citationRows($results),
            ['平均輸入 / 輸出 Token（呼叫 LLM 的題目）', $avg($called, fn (AnswerResult $r) => $r->answer->usage->inputTokens).' / '.$avg($called, fn (AnswerResult $r) => $r->answer->usage->outputTokens)],
            ['平均耗時：檢索 / LLM（ms）', $avg($answered, fn (AnswerResult $r) => $r->answer->retrievalMs).' / '.$avg($called, fn (AnswerResult $r) => $r->answer->llmMs)],
        ];
    }

    /**
     * Ch10 的引用指標。「已回答」指 status 為 answered 的題目。
     *
     * @param  list<AnswerResult>  $results
     * @return list<array{string, string}>
     */
    private function citationRows(array $results): array
    {
        $answered = array_filter($results, fn (AnswerResult $r) => $r->answer?->status === AnswerStatus::Answered);
        $hits = array_filter($answered, fn (AnswerResult $r) => $r->citationHit() !== null);
        $noAnswer = array_filter($results, fn (AnswerResult $r) => $r->question->type === QuestionType::NoAnswer && $r->answer !== null);
        $invalid = collect($results)->flatMap(fn (AnswerResult $r) => $r->answer->invalidRefs ?? [])->countBy(fn (InvalidRef $i) => $i->reason->value);
        $ratio = fn (int $n, array $of) => $of === [] ? '—' : "{$n} / ".count($of);

        return [
            ['引用率（已回答題中至少一個合法引用）', $ratio(count(array_filter($answered, fn (AnswerResult $r) => $r->citations !== [])), $answered)],
            ['命中率（已回答題中引用到預期段落）', $ratio(count(array_filter($hits, fn (AnswerResult $r) => $r->citationHit())), $hits)],
            ['平均引用數（已回答題）', $answered === [] ? '—' : sprintf('%.2f', array_sum(array_map(fn (AnswerResult $r) => count($r->citations), $answered)) / count($answered))],
            ['不合規標記：'.implode(' / ', array_map(fn (InvalidReason $r) => $r->value, InvalidReason::cases())), implode(' / ', array_map(fn (InvalidReason $r) => $invalid->get($r->value, 0), InvalidReason::cases()))],
            ['無答案題未顯示來源', $ratio(count(array_filter($noAnswer, fn (AnswerResult $r) => $r->answer->citations === [])), $noAnswer)],
        ];
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
            '| id | 題型 | 問題 |'.($withExpected ? ' 預期答案與判讀標準 |' : '').' status | LLM | Top-1 | 回答 | [n] | 命中 | 不合規標記 | 自動檢查 | Token（入/出） | 結束原因 | LLM ms | 人工判讀 |',
            '|'.str_repeat(' --- |', $withExpected ? 16 : 15),
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
                match ($r->citationHit()) {
                    null => '—',
                    true => '✓',
                    false => '✗',
                },
                $a === null || $a->invalidRefs === [] ? '—' : $cell(implode(' ', array_map(fn (InvalidRef $i) => "{$i->raw}（{$i->reason->value}）", $a->invalidRefs))),
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
