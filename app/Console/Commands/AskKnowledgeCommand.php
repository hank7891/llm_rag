<?php

namespace App\Console\Commands;

use App\Rag\Answer\AnswerOptions;
use App\Rag\Answer\QuerySource;
use App\Rag\Answer\RagAnswerService;
use App\Rag\Retrieval\RetrievedChunk;
use Illuminate\Console\Command;

/**
 * 以 RAG 回答問題（除錯用）：依序列出檢索結果、送給 LLM 的訊息、回答與耗時。
 */
class AskKnowledgeCommand extends Command
{
    protected $signature = 'rag:ask {question : 問題}
        {--provider= : Chat Provider（ollama / openai / gemini），未填使用預設值}
        {--show-context : 列出實際送給 LLM 的 System 與 User 訊息}
        {--conversation= : 接續既有對話的 conversation_id（Ch13）}
        {--show-rewrite : 列出改寫前後的問題與改寫狀態}';

    protected $description = '以 RAG 回答問題，顯示檢索結果、回答、Token 用量與耗時';

    public function handle(RagAnswerService $rag): int
    {
        $conversationId = $this->option('conversation') === null ? null : (int) $this->option('conversation');
        $answer = $rag->answer($this->argument('question'), new AnswerOptions($this->option('provider'), QuerySource::Cli, $conversationId));
        $retrieval = $answer->retrieval;

        if ($this->option('show-rewrite') && $answer->rewrite !== null) {
            $this->info('── 改寫 ──');
            $this->line("原始問題：{$answer->originalQuestion}");
            $this->line("檢索用問題：{$answer->rewrite->question}");
            $this->line(sprintf('狀態：%s　呼叫改寫 LLM：%s　耗時：%s%s',
                $answer->rewrite->status->value, $answer->rewrite->called ? 'true' : 'false', $answer->rewrite->latencyMs === null ? '—' : "{$answer->rewrite->latencyMs} ms",
                $answer->rewrite->failureReason === null ? '' : "　降級原因：{$answer->rewrite->failureReason}"));
        }

        $this->info(sprintf('檢索結果（模型 %s、Top-K %d、門檻 > %s）', $retrieval->model, $retrieval->topK, $retrieval->scoreThreshold));
        if ($retrieval->hasCandidates()) {
            $this->table(['#', '分數', '檔名', 'section', '頁碼'], array_map(fn (int $i, RetrievedChunk $c) => [
                $i + 1, sprintf('%.4f', $c->score), $c->documentName, $c->section ?? '—', $c->pageStart === $c->pageEnd ? $c->pageStart : "{$c->pageStart}–{$c->pageEnd}",
            ], array_keys($retrieval->chunks), $retrieval->chunks));
        } else {
            $this->warn(sprintf('無候選：最高分 %s 未超過門檻，不呼叫 LLM。', $retrieval->topScore() === null ? '—' : sprintf('%.4f', $retrieval->topScore())));
        }

        if ($this->option('show-context')) {
            foreach ($answer->messages as $message) {
                $this->info("── {$message->role->value} ──");
                $this->line($message->content);
            }
        }

        $this->info('── 回答 ──');
        $this->line($answer->answer);

        if ($answer->sources !== []) {
            $this->newLine();
            $this->info('── 資料來源 ──');
            foreach ($answer->sources as $line) {
                $this->line($line);
            }
        }

        if ($this->option('show-context') && $answer->llmCalled) {
            $this->newLine();
            $this->info('── LLM 原始回答 ──');
            $this->line($answer->rawAnswer);
            $this->info('── 被移除的引用標記 ──');
            $this->line($answer->invalidRefs === [] ? '無' : implode('、', array_map(fn ($r) => "{$r->raw}（{$r->reason->value}）", $answer->invalidRefs)));
        }

        $this->newLine();
        $this->line(sprintf('status：%s　llm_called：%s　參考資料：%d 段（捨去 %d 段）　引用：%d　不合規標記：%d　uncited：%s', $answer->status->value, $answer->llmCalled ? 'true' : 'false', count($answer->references), $answer->droppedChunks, count($answer->citations), count($answer->invalidRefs), $answer->uncited ? 'true' : 'false'));
        $this->line(sprintf(
            'Provider：%s　模型：%s　Token：輸入 %s／輸出 %s　耗時：檢索 %d ms、LLM %s',
            $answer->provider,
            $answer->model ?? '—',
            $answer->usage?->inputTokens ?? '—',
            $answer->usage?->outputTokens ?? '—',
            $answer->retrievalMs,
            $answer->llmMs === null ? '—' : "{$answer->llmMs} ms",
        ));

        if ($answer->conversationId !== null) {
            $this->line("conversation_id：{$answer->conversationId}（接續提問：--conversation={$answer->conversationId}）");
        }

        return self::SUCCESS;
    }
}
