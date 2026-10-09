<?php

namespace App\Rag\Evaluation;

use App\Rag\Answer\AnswerOptions;
use App\Rag\Answer\QuerySource;
use App\Rag\Answer\RagAnswerService;
use App\Rag\Citation\CitationParser;
use App\Rag\Conversation\HistoryWindow;
use App\Rag\Conversation\QueryRewriter;
use App\Rag\Conversation\Turn;
use App\Rag\Retrieval\RetrievedChunk;
use App\Rag\Retrieval\RetrieveOptions;
use App\Rag\Retrieval\RetrieverService;
use InvalidArgumentException;

/**
 * 多輪檢索評估（Ch13）。三種檢索用問題：
 * none：只用追問（對照組 A）；concat：Window 內的歷史使用者問題串在追問前面（B）；llm：Query Rewriting（C）。
 */
class ConversationEvaluator
{
    public const MODES = ['none', 'concat', 'llm'];

    public function __construct(
        private readonly RetrieverService $retriever,
        private readonly QueryRewriter $rewriter,
        private readonly RagAnswerService $rag,
        private readonly int $defaultWindow,
        private readonly int $historyBudgetChars,
    ) {}

    /** @return list<ConversationCase> */
    public function load(string $path): array
    {
        return array_map(function (string $line, int $i) use ($path) {
            $row = json_decode($line, true) ?? throw new InvalidArgumentException("{$path} 第 ".($i + 1).' 行不是有效的 JSON。');

            return new ConversationCase(
                $row['id'],
                $row['type'],
                array_map(fn (array $t) => new Turn($t['question'], $t['answer']), $row['history']),
                $row['question'],
                array_map(fn (array $e) => new ExpectedSource($e['document'], $e['section']), $row['expected']),
                $row['expected_rewrite'] ?? null,
                $row['note'] ?? null,
            );
        }, $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), array_keys($lines));
    }

    /**
     * @param  list<ConversationCase>  $cases
     * @return list<ConversationResult>
     */
    public function evaluate(array $cases, string $mode, ?int $window = null, ?string $rewriteProvider = null, ?string $promptVersion = null, ?bool $rerank = null, bool $answer = false): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown rewrite mode [{$mode}]. Available: ".implode(', ', self::MODES).'.');
        }

        $history = new HistoryWindow(new CitationParser, $window ?? $this->defaultWindow, $this->historyBudgetChars);

        $windowTurns = $window ?? $this->defaultWindow;

        return array_map(function (ConversationCase $case) use ($mode, $history, $windowTurns, $rewriteProvider, $promptVersion, $rerank, $answer) {
            $turns = $history->apply($case->history);
            $rewrite = $mode === 'llm' ? $this->rewriter->rewrite($case->question, $turns, provider: $rewriteProvider, promptVersion: $promptVersion) : null;
            $query = match ($mode) {
                'none' => $case->question,
                'concat' => trim(implode(' ', array_map(fn (Turn $t) => $t->question, $turns)).' '.$case->question),
                'llm' => $rewrite->question,
            };

            $startedAt = hrtime(true);
            $retrieval = $this->retriever->retrieve($query, new RetrieveOptions(topK: 20, rerank: $rerank));
            $retrievalMs = intdiv(hrtime(true) - $startedAt, 1_000_000);
            $index = collect($retrieval->chunks)->search(fn (RetrievedChunk $c) => collect($case->expected)->contains(fn (ExpectedSource $e) => $e->matches($c)));

            // --answer：以相同的腳本化歷史走完整問答（RagAnswerService 自己改寫），記錄回答 LLM 的輸入 Token 數
            $full = $answer ? $this->rag->answer($case->question, new AnswerOptions(source: QuerySource::Eval, history: $case->history)) : null;

            return new ConversationResult($case, $query, $rewrite, $retrieval, $index === false ? null : $index + 1, $retrievalMs, min(count($case->history), $windowTurns), count($turns), $full?->usage?->inputTokens, $full?->answer);
        }, $cases);
    }
}
