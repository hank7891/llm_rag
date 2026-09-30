<?php

namespace App\Documents\Exceptions;

use App\Documents\DocumentStatus;
use RuntimeException;

/**
 * 要轉換狀態時，文件的狀態已被其他程序改變（例如另一個 Job 已處理完成）。
 */
class StaleDocumentStatusException extends RuntimeException
{
    public static function for(int $documentId, DocumentStatus $expected): self
    {
        return new self(sprintf('Document #%d is no longer [%s]; it was changed by another process.', $documentId, $expected->value));
    }
}
