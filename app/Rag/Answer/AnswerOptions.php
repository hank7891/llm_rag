<?php

namespace App\Rag\Answer;

/**
 * 問答參數。provider 為 null 時使用 rag.answer.default_provider，再沒有就用 llm.chat.default。
 * source 記錄在 rag_query_logs，區分實際提問與測試題。
 */
final readonly class AnswerOptions
{
    public function __construct(
        public ?string $provider = null,
        public QuerySource $source = QuerySource::Api,
    ) {}
}
