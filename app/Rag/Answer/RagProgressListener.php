<?php

namespace App\Rag\Answer;

use App\Rag\Conversation\RewriteResult;
use App\Rag\Retrieval\RetrievalResult;

/**
 * RagAnswerService 的進度回呼。串流與非串流共用同一個流程：非串流不傳 Listener，
 * 串流由呼叫端實作（例如轉成 SSE 事件）；有 Listener 時回答階段改用 ChatService::stream()。
 */
interface RagProgressListener
{
    public function conversation(?int $conversationId): void;

    public function stage(RagStage $stage): void;

    public function rewrite(RewriteResult $rewrite): void;

    public function retrieval(RetrievalResult $retrieval): void;

    /** 回答生成中的一段暫時文字；最終答案以 RagAnswer 為準（引用要等全文才能檢查） */
    public function delta(string $text): void;

    /** 呼叫端已離開（例如使用者關閉頁面）時回傳 true：停止讀取 LLM 串流，這則回答標記為 interrupted */
    public function cancelled(): bool;
}
