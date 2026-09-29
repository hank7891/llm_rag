<?php

namespace App\Ai\Chat\Providers;

use App\Ai\Chat\Contracts\ChatProviderInterface;
use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\ChatResult;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Chat\DTO\Usage;

/**
 * 不呼叫任何模型的 Provider：回顯最後一則 user 訊息，並記錄收到的參數供測試檢查。
 * 放在 app/ 而非 tests/：它是 config/llm.php 的預設 provider，--no-dev 部署時也必須載入得到。
 */
class FakeChatProvider implements ChatProviderInterface
{
    protected const REPLY_PREFIX = 'Echo: ';

    protected const CHUNK_LENGTH = 4;

    /** @var list<Message>|null */
    public ?array $lastMessages = null;

    public ?ChatOptions $lastOptions = null;

    public function chat(array $messages, ChatOptions $options): ChatResult
    {
        $this->lastMessages = $messages;
        $this->lastOptions = $options;

        return new ChatResult(
            content: $this->reply($messages),
            usage: $this->usage(),
            model: $options->model ?? 'fake',
            finishReason: FinishReason::Stop,
        );
    }

    public function stream(array $messages, ChatOptions $options): iterable
    {
        $this->lastMessages = $messages;
        $this->lastOptions = $options;

        // 以 mb_str_split 依「字元」切段；str_split 依 byte 切會把中文切成亂碼
        $pieces = mb_str_split($this->reply($messages), static::CHUNK_LENGTH);
        $lastIndex = array_key_last($pieces);

        foreach ($pieces as $index => $piece) {
            yield $index === $lastIndex
                ? new StreamChunk($piece, $this->usage(), FinishReason::Stop, $options->model ?? 'fake')
                : new StreamChunk($piece);
        }
    }

    /** @param list<Message> $messages */
    protected function reply(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if ($message->role === Role::User) {
                return static::REPLY_PREFIX.$message->content;
            }
        }

        return static::REPLY_PREFIX;
    }

    protected function usage(): Usage
    {
        return new Usage(inputTokens: 10, outputTokens: 20);
    }
}
