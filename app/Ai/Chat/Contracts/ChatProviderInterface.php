<?php

namespace App\Ai\Chat\Contracts;

use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\ChatResult;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\StreamChunk;

/**
 * Chat 能力介面。實作者負責把內部 DTO 轉成自家 API 格式，再把回應轉回內部 DTO。
 */
interface ChatProviderInterface
{
    /** @param list<Message> $messages */
    public function chat(array $messages, ChatOptions $options): ChatResult;

    /**
     * @param  list<Message>  $messages
     * @return iterable<StreamChunk>
     */
    public function stream(array $messages, ChatOptions $options): iterable;
}
