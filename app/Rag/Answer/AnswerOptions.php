<?php

namespace App\Rag\Answer;

use App\Rag\Conversation\Turn;

/**
 * 問答參數。provider 為 null 時使用 rag.answer.default_provider，再沒有就用 llm.chat.default。
 * source 記錄在 rag_query_logs，區分實際提問與測試題。
 * conversationId：接續既有對話（Ch13）；null 時建立新對話（source 為 eval 時不建立，不留下測試題的對話紀錄）。
 * history：評估用的腳本化歷史（rag:eval-conversation --answer）。有值時不讀寫資料庫，仍經過 Sliding Window。
 */
final readonly class AnswerOptions
{
    /** @param list<Turn>|null $history */
    public function __construct(
        public ?string $provider = null,
        public QuerySource $source = QuerySource::Api,
        public ?int $conversationId = null,
        public ?array $history = null,
    ) {}
}
