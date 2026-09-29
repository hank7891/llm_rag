<?php

namespace App\Console\Commands;

use App\Ai\Chat\ChatService;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Chat\DTO\Usage;
use Illuminate\Console\Command;

/**
 * 手動驗證：以內建三輪對話實際呼叫模型，確認第三輪能引用前兩輪。
 * 與 Controller 一樣只依賴 ChatService，不因 Provider 分支。
 */
class LlmTryCommand extends Command
{
    protected $signature = 'llm:try {provider? : provider 名稱，未填使用預設值} {--stream : 以串流方式逐段輸出}';

    protected $description = '以內建三輪對話實際呼叫 LLM，印出回答與 Token 用量';

    public function handle(ChatService $chat): int
    {
        $provider = $this->argument('provider');
        $system = new Message(Role::System, '請使用繁體中文（台灣用語）回答，三句話以內。');
        $firstQuestion = new Message(Role::User, '請簡單介紹 RAG');

        $this->info('第一輪 user：'.$firstQuestion->content);
        $firstAnswer = $this->converse($chat, [$system, $firstQuestion], $provider);

        $third = new Message(Role::User, '那它跟微調有什麼不同？');
        $this->newLine();
        $this->info('第三輪 user：'.$third->content.'（第二輪 assistant 帶入上面的實際回答）');
        $this->converse($chat, [$system, $firstQuestion, new Message(Role::Assistant, $firstAnswer), $third], $provider);

        return self::SUCCESS;
    }

    /** @param list<Message> $messages */
    private function converse(ChatService $chat, array $messages, ?string $provider): string
    {
        $startedAt = microtime(true);

        if (! $this->option('stream')) {
            $result = $chat->chat($messages, provider: $provider);
            $this->line($result->content);
            $this->summary($result->model, $result->usage, $result->finishReason, $startedAt);

            return $result->content;
        }

        $answer = '';

        foreach ($chat->stream($messages, provider: $provider) as $chunk) {
            $this->output->write($chunk->delta);
            $answer .= $chunk->delta;

            if ($chunk->usage !== null) {
                $this->newLine();
                $this->summary($chunk->model, $chunk->usage, $chunk->finishReason, $startedAt);
            }
        }

        return $answer;
    }

    private function summary(?string $model, Usage $usage, ?FinishReason $finishReason, float $startedAt): void
    {
        $this->comment(sprintf(
            '↳ %s | input %d / output %d tokens | %s | %.1fs',
            $model,
            $usage->inputTokens,
            $usage->outputTokens,
            $finishReason?->value,
            microtime(true) - $startedAt,
        ));
    }
}
