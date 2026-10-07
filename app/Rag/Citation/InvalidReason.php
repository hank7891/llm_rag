<?php

namespace App\Rag\Citation;

/**
 * 被移除的引用標記原因。
 */
enum InvalidReason: string
{
    // 編號不在本次的編號對照表內（例如只有 5 筆卻寫 [9]）
    case OutOfRange = 'out_of_range';

    // 方括號內不是編號（例如 [資料不足]、[來源]）
    case NonNumeric = 'non_numeric';

    // 編號合法，但 MySQL 查不到這段 Chunk（文件已刪除、索引不同步）
    case SourceMissing = 'source_missing';

    // 資料不足的回答（門檻擋下或 LLM 判斷不足）不顯示來源，其中的編號一律移除
    case InsufficientAnswer = 'insufficient_answer';
}
