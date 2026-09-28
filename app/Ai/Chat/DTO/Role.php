<?php

namespace App\Ai\Chat\DTO;

/**
 * 訊息角色（內部格式）。各家 API 的角色名稱差異由 Provider 轉換，例如 Gemini 的 assistant 為 model。
 */
enum Role: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
