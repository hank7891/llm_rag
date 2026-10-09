<?php

namespace App\Http\Requests;

use App\Repositories\ConversationRepository;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * 同一段對話不可中途更換 Provider（改寫跟隨回答 Provider，換了等於換改寫模型與資料外送對象）。
     *
     * @return list<Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $id = $this->input('conversation_id');
            $provider = $this->input('provider');
            if ($validator->errors()->isNotEmpty() || $id === null || $provider === null) {
                return;
            }

            $current = $this->container->make(ConversationRepository::class)->find((int) $id)?->provider;
            if ($current !== null && $current !== $provider) {
                $validator->errors()->add('provider', "這段對話使用 {$current}，要更換 Provider 請開始新對話。");
            }
        }];
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
