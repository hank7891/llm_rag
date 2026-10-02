<?php

namespace App\Documents\Exceptions;

use App\Documents\DocumentStatus;
use RuntimeException;

/**
 * 文件尚未解析完成（或解析失敗），還沒有可以提問的頁面。訊息會顯示給使用者。
 */
class DocumentNotReadyException extends RuntimeException
{
    public static function for(int $documentId, DocumentStatus $status): self
    {
        return new self("文件 #{$documentId} 目前的狀態為「{$status->label()}」，解析完成後才能提問。");
    }
}
