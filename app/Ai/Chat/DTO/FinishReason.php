<?php

namespace App\Ai\Chat\DTO;

/**
 * 回答結束原因（內部格式）。各家原始值由 Provider 轉換，例如 Anthropic end_turn → Stop、max_tokens → Length。
 */
enum FinishReason: string
{
    // 模型正常說完
    case Stop = 'stop';

    // 達到輸出長度上限而截斷
    case Length = 'length';

    // 模型拒答或被安全機制擋下
    case ContentFilter = 'content_filter';

    // 其他或無法辨識的原因
    case Other = 'other';
}
