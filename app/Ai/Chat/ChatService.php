<?php

namespace App\Ai\Chat;

use App\Ai\Chat\Contracts\ChatProviderInterface;
use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\ChatResult;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Chat\Exceptions\InvalidMessagesException;
use App\Ai\Chat\Exceptions\UnknownChatProviderException;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * 業務層呼叫 LLM 的唯一入口：驗證 messages、依名稱取得 Provider 後轉交。
 * 不做格式轉換（Provider 的責任），也不刪減或改動 messages（截斷策略屬於 Ch13）。
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
    ) {}

    /** @param list<Message> $messages */
    public function chat(array $messages, ?ChatOptions $options = null, ?string $provider = null): ChatResult
    {
        $this->validate($messages);

        return $this->provider($provider)->chat($messages, $options ?? new ChatOptions);
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

        return $this->provider($provider)->stream($messages, $options ?? new ChatOptions);
    }

    private function provider(?string $name): ChatProviderInterface
    {
        $name ??= $this->defaultProvider;

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

        foreach ($messages as $key => $message) {
            if (! $message instanceof Message) {
                throw InvalidMessagesException::notMessage($key);
            }
        }

        if (end($messages)->role !== Role::User) {
            throw InvalidMessagesException::lastNotUser();
        }
    }
}
