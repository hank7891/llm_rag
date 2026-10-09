<?php

namespace App\Console\Commands;

use App\Rag\Answer\AnswerOptions;
use App\Rag\Answer\QuerySource;
use App\Rag\Answer\RagAnswerService;
use Illuminate\Console\Command;

/**
 * 在終端機連續提問（多輪對話 RAG，除錯用）。歷史由資料庫讀取，走與 API 相同的路徑。
 * 輸入空白行或 exit 結束。
 */
class ChatKnowledgeCommand extends Command
{
    protected $signature = 'rag:chat
        {--provider= : 回答用的 Chat Provider，未填使用預設值}
        {--conversation= : 接續既有對話的 conversation_id}';

    protected $description = '在終端機以多輪對話提問，每一輪列出改寫結果、回答與來源';

    public function handle(RagAnswerService $rag): int
    {
        $conversationId = $this->option('conversation') === null ? null : (int) $this->option('conversation');

        while (true) {
            $question = trim((string) $this->ask('問題（空白或 exit 結束）', ''));
            if ($question === '' || $question === 'exit') {
                return self::SUCCESS;
            }

            $answer = $rag->answer($question, new AnswerOptions($this->option('provider'), QuerySource::Cli, $conversationId));
            $conversationId = $answer->conversationId;

            $this->line(sprintf('［conversation %s］歷史 %d 輪　檢索用問題：%s（%s%s）',
                $conversationId ?? '—', $answer->historyTurns, $answer->rewrite?->question ?? $question, $answer->rewrite?->status->value ?? '—',
                $answer->rewrite?->latencyMs === null ? '' : "，{$answer->rewrite->latencyMs} ms"));
            $this->info($answer->answer);
            foreach ($answer->sources as $line) {
                $this->line("  {$line}");
            }
            $this->line(sprintf('  （status：%s　檢索 %d ms　LLM %s）', $answer->status->value, $answer->retrievalMs, $answer->llmMs === null ? '—' : "{$answer->llmMs} ms"));
            $this->newLine();
        }
    }
}
