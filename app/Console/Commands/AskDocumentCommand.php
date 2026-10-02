<?php

namespace App\Console\Commands;

use App\Ai\Chat\DTO\ChatOptions;
use App\Documents\Qa\DocumentQaService;
use App\Repositories\DocumentRepository;
use Illuminate\Console\Command;

/**
 * 實驗用：對單一文件提問，以表格輸出回答與 Token 用量，方便重複比較不同 num_ctx 與 Provider。
 */
class AskDocumentCommand extends Command
{
    protected $signature = 'rag:ask-doc
        {document : 文件 id}
        {question : 問題}
        {--provider= : provider 名稱，未填使用預設值}
        {--num-ctx= : 覆寫 Ollama 的 num_ctx}
        {--no-truncate : 超過 num_ctx 時讓 Ollama 直接回錯誤，而不是靜默截斷}';

    protected $description = '對單一文件提問（整份文件交給 LLM，不使用 RAG），用於 num_ctx 與 Provider 對照實驗';

    public function handle(DocumentQaService $qa, DocumentRepository $documents): int
    {
        $document = $documents->findOrFail((int) $this->argument('document'));

        $ollama = array_filter([
            'num_ctx' => $this->option('num-ctx') === null ? null : (int) $this->option('num-ctx'),
            'truncate' => $this->option('no-truncate') ? false : null,
        ], fn ($value) => $value !== null);

        $answer = $qa->ask(
            $document,
            $this->argument('question'),
            new ChatOptions(providerOptions: $ollama === [] ? [] : ['ollama' => $ollama]),
            $this->option('provider'),
        );
        $result = $answer->result;

        $this->line($result->content);
        $this->newLine();
        $this->table(['項目', '值'], [
            ['文件', "#{$document->id} {$document->name}（{$answer->context->pageCount} 頁）"],
            ['模型', $result->model],
            ['num_ctx', $ollama['num_ctx'] ?? '（設定值）'],
            ['文件字數', $answer->context->chars],
            ['粗估 Token', $answer->context->estimatedTokens],
            ['input tokens（prompt_eval_count）', $result->usage->inputTokens],
            ['output tokens', $result->usage->outputTokens],
            ['疑似截斷', match ($result->inputTruncated) {
                true => '是',
                false => '否',
                null => '無法判斷',
            }],
            ['結束原因', $result->finishReason->value],
            ['耗時', sprintf('%.1f 秒', $answer->durationMs / 1000)],
        ]);

        return self::SUCCESS;
    }
}
