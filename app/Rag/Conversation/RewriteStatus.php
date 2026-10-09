<?php

namespace App\Rag\Conversation;

/**
 * 改寫狀態。
 */
enum RewriteStatus: string
{
    // 沒有歷史（第一輪）或多輪功能關閉：直接使用原問題，不呼叫 LLM
    case Skipped = 'skipped';

    // 已改寫成獨立問題，檢索使用改寫結果
    case Rewritten = 'rewritten';

    // 有呼叫 LLM，但結果與原問題相同（忽略空白與標點）：問題本來就完整，或模型沒有補上省略的對象
    case Unchanged = 'unchanged';

    // 改寫失敗（逾時、空白、過長、像答案）：降級為原問題
    case Fallback = 'fallback';
}
