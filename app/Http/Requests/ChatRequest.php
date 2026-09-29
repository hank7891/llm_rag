<?php

namespace App\Http\Requests;

use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/ai/chat 與 /api/ai/chat/stream 共用：驗證 JSON 並轉成內部 DTO。
 * 這裡只驗證「格式」；對話規則（system 只能在開頭、最後一則是 user）由 ChatService 負責，
 * 讓 artisan 指令等其他呼叫端也受到同樣的保護。
 */
class ChatRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'provider' => ['nullable', 'string', Rule::in(array_keys(config('llm.chat.providers')))],
            'messages' => ['required', 'array', 'list', 'min:1'],
            'messages.*.role' => ['required', Rule::enum(Role::class)],
            'messages.*.content' => ['required', 'string'],
            'options' => ['nullable', 'array'],
            'options.model' => ['nullable', 'string'],
            'options.temperature' => ['nullable', 'numeric', 'between:0,2'],
            'options.max_tokens' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function providerName(): ?string
    {
        return $this->validated('provider');
    }

    /**
     * 不可命名為 messages()：FormRequest 已用它提供自訂驗證訊息，覆寫會破壞驗證流程。
     *
     * @return list<Message>
     */
    public function chatMessages(): array
    {
        return array_map(
            fn (array $m) => new Message(Role::from($m['role']), $m['content']),
            $this->validated('messages'),
        );
    }

    public function chatOptions(): ChatOptions
    {
        $temperature = $this->validated('options.temperature');

        return new ChatOptions(
            model: $this->validated('options.model'),
            temperature: $temperature === null ? null : (float) $temperature,
            maxTokens: $this->validated('options.max_tokens'),
        );
    }
}
