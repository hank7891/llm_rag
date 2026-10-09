<?php

namespace App\Rag\Answer;

/**
 * 回答結果的狀態。
 */
enum AnswerStatus: string
{
    case Answered = 'answered';

    // 第一道防線：沒有候選通過門檻，未呼叫 LLM
    case InsufficientNoCandidates = 'insufficient_no_candidates';

    // 第二道防線：有候選，但 LLM 判斷參考資料不足以回答
    case InsufficientByLlm = 'insufficient_by_llm';

    // 串流中呼叫端中斷（例如關閉頁面）：回答只有一部分，不進入下一輪的對話歷史
    case Interrupted = 'interrupted';

    public function isInsufficient(): bool
    {
        return $this === self::InsufficientNoCandidates || $this === self::InsufficientByLlm;
    }
}
