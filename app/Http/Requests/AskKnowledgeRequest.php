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
        ];
    }

    public function providerName(): ?string
    {
        return $this->validated('provider');
    }
}
