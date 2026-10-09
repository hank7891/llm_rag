<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AskKnowledgeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:2000'],
            'provider' => ['nullable', 'string', Rule::in(array_keys(config('llm.chat.providers')))],
            // 接續既有對話（Ch13）；不帶時建立新對話。歷史一律由 server 端讀取，Client 不能自行帶入
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
        ];
    }

    public function providerName(): ?string
    {
        return $this->validated('provider');
    }

    public function conversationId(): ?int
    {
        $id = $this->validated('conversation_id');

        return $id === null ? null : (int) $id;
    }
}
