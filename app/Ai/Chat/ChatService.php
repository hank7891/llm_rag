<?php

namespace App\Ai\Chat;

use App\Ai\Chat\Contracts\ChatProviderInterface;
use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\ChatResult;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Chat\DTO\Usage;
use App\Ai\Chat\Exceptions\InvalidMessagesException;
use App\Ai\Chat\Exceptions\UnknownChatProviderException;
use Generator;
use Illuminate\Contracts\Container\Container;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 業務層呼叫 LLM 的唯一入口：驗證 messages、依名稱取得 Provider 後轉交，並記錄用量。
 * 不做格式轉換（Provider 的責任），也不刪減或改動 messages（截斷策略屬於 Ch13）。
 * 用量記錄放在這裡而不是各 Provider：所有 Provider 共通，寫一次即可。
 */
class ChatService
{
    /**
     * @param  array<string, class-string<ChatProviderInterface>>  $providers  provider 名稱 → 類別
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $providers,
        private readonly string $defaultProvider,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param list<Message> $messages */
    public function chat(array $messages, ?ChatOptions $options = null, ?string $provider = null): ChatResult
    {
        $this->validate($messages);
        $name = $provider ?? $this->defaultProvider;
        $instance = $this->provider($name);
        $startedAt = hrtime(true);

        try {
            $result = $instance->chat($messages, $options ?? new ChatOptions);
        } catch (Throwable $e) {
            $this->logFailure($name, $startedAt, $e, stream: false);

            throw $e;
        }

        $this->logUsage($name, $startedAt, $result->model, $result->usage, $result->finishReason, stream: false);

        return $result;
    }

    /**
     * 刻意不在此 yield：含 yield 的函式會變成 Generator，要等第一次迭代才執行，
     * 驗證錯誤會延後到開始讀取串流時才丟出。
     *
     * @param  list<Message>  $messages
     * @return iterable<StreamChunk>
     */
    public function stream(array $messages, ?ChatOptions $options = null, ?string $provider = null): iterable
    {
        $this->validate($messages);
        $name = $provider ?? $this->defaultProvider;

        return $this->loggedStream($name, $this->provider($name), $messages, $options ?? new ChatOptions);
    }

    /**
     * 包裝 Provider 的串流，在最後一段（帶 usage）送出後記錄用量。
     * 呼叫端中途停止讀取（例如使用者關閉連線）時不會有最後一段，也就不會記錄。
     *
     * @param  list<Message>  $messages
     * @return Generator<int, StreamChunk>
     */
    private function loggedStream(string $name, ChatProviderInterface $instance, array $messages, ChatOptions $options): Generator
    {
        $startedAt = hrtime(true);

        try {
            foreach ($instance->stream($messages, $options) as $chunk) {
                yield $chunk;

                if ($chunk->usage !== null) {
                    $this->logUsage($name, $startedAt, $chunk->model, $chunk->usage, $chunk->finishReason, stream: true);
                }
            }
        } catch (Throwable $e) {
            $this->logFailure($name, $startedAt, $e, stream: true);

            throw $e;
        }
    }

    private function logUsage(string $name, int $startedAt, ?string $model, Usage $usage, ?FinishReason $finishReason, bool $stream): void
    {
        $this->logger->info('llm.chat', [
            'provider' => $name,
            'model' => $model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'finish_reason' => $finishReason?->value,
            'duration_ms' => $this->elapsedMs($startedAt),
            'stream' => $stream,
        ]);
    }

    private function logFailure(string $name, int $startedAt, Throwable $e, bool $stream): void
    {
        // 例外訊息已由 LlmHttp 遮蔽 API Key
        $this->logger->warning('llm.chat.failed', [
            'provider' => $name,
            'error' => class_basename($e),
            'message' => $e->getMessage(),
            'duration_ms' => $this->elapsedMs($startedAt),
            'stream' => $stream,
        ]);
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }

    private function provider(string $name): ChatProviderInterface
    {
        $class = $this->providers[$name]
            ?? throw UnknownChatProviderException::named($name, array_keys($this->providers));

        $instance = $this->container->make($class);

        if (! $instance instanceof ChatProviderInterface) {
            throw new LogicException(sprintf(
                'Chat provider [%s] (%s) must implement %s.',
                $name,
                $class,
                ChatProviderInterface::class,
            ));
        }

        return $instance;
    }

    /** @param array<mixed> $messages */
    private function validate(array $messages): void
    {
        if ($messages === []) {
            throw InvalidMessagesException::empty();
        }

        $conversationStarted = false;

        foreach ($messages as $key => $message) {
            if (! $message instanceof Message) {
                throw InvalidMessagesException::notMessage($key);
            }

            // system 只能連續出現在開頭：部分供應商（如 Anthropic）只有頂層 system 參數，無法表達「從中段才生效」
            if ($message->role !== Role::System) {
                $conversationStarted = true;
            } elseif ($conversationStarted) {
                throw InvalidMessagesException::systemNotLeading($key);
            }
        }

        if (end($messages)->role !== Role::User) {
            throw InvalidMessagesException::lastNotUser();
        }
    }
}
