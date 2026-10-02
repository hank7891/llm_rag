<?php

namespace App\Http\Requests;

use App\Ai\Chat\DTO\ChatOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AskDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:2000'],
            'provider' => ['nullable', 'string', Rule::in(array_keys(config('llm.chat.providers')))],
            // 只對 Ollama 有效，其他 Provider 會忽略
            'num_ctx' => ['nullable', 'integer', 'min:512', 'max:131072'],
        ];
    }

    public function providerName(): ?string
    {
        return $this->validated('provider');
    }

    /** num_ctx 是 Ollama 特有參數，依 Ch04 的決定放進 providerOptions，其他 Provider 不會讀取 */
    public function chatOptions(): ChatOptions
    {
        $numCtx = $this->validated('num_ctx');

        return new ChatOptions(providerOptions: $numCtx === null ? [] : ['ollama' => ['num_ctx' => (int) $numCtx]]);
    }
}
