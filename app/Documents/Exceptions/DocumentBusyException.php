<?php

namespace App\Documents\Exceptions;

use App\Documents\DocumentStatus;
use RuntimeException;

/**
 * 文件正在處理中，不可刪除：Job 可能在 Qdrant 清除之後才寫入 Points，留下孤兒。訊息會顯示給使用者。
 */
class DocumentBusyException extends RuntimeException
{
    public static function cannotDelete(string $name, DocumentStatus $status): self
    {
        return new self("「{$name}」目前為「{$status->label()}」，處理完成後才能刪除。");
    }
}
