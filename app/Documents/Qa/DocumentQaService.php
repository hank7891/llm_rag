<?php

namespace App\Documents\Qa;

use App\Ai\Chat\ChatService;
use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Documents\DocumentStatus;
use App\Documents\Exceptions\DocumentNotReadyException;
use App\Models\Document;

/**
 * 不使用 RAG，把整份文件與問題一起交給 LLM（Ch04 的 baseline）。
 * 只依賴 ChatService，不知道底層是哪一家 Provider。
 */
class DocumentQaService
{
    /** 已解析完成、可以提問的狀態 */
    private const READY = [DocumentStatus::Parsed, DocumentStatus::Indexing, DocumentStatus::Indexed];

    public function __construct(
        private readonly ChatService $chat,
        private readonly DocumentContextBuilder $contexts,
        private readonly string $systemPrompt,
    ) {}

    /**
     * @throws DocumentNotReadyException 文件尚未解析完成
     */
    public function ask(Document $document, string $question, ?ChatOptions $options = null, ?string $provider = null): DocumentAnswer
    {
        if (! in_array($document->status_key, self::READY, true)) {
            throw DocumentNotReadyException::for($document->id, $document->status_key);
        }

        $context = $this->contexts->build($document->id);
        $startedAt = hrtime(true);

        $result = $this->chat->chat($this->messages($context, $question), $options, $provider);

        return new DocumentAnswer($result, $context, intdiv(hrtime(true) - $startedAt, 1_000_000));
    }

    /**
     * 指令與資料分開：system 只放規則；文件放在 user 訊息並以 <document> 標籤包住，
     * 讓模型分得出哪些是要遵守的指令、哪些只是參考資料。
     *
     * @return list<Message>
     */
    private function messages(DocumentContext $context, string $question): array
    {
        return [
            new Message(Role::System, $this->systemPrompt),
            new Message(Role::User, "<document>\n{$context->text}\n</document>\n\n問題：{$question}"),
        ];
    }
}
