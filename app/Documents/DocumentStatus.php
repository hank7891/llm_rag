<?php

namespace App\Documents;

/**
 * 文件處理狀態與合法的狀態轉換（State Machine）。
 *
 * uploaded → parsing → parsed → chunking → chunked → indexing → indexed
 * chunking / chunked（Ch05 新增）：與 parsing / parsed 相同模式，讓切段進度可見，
 * 並以樂觀鎖防止兩個切段 Job 同時執行。indexing / indexed 於 Ch06 起使用。
 */
enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
    case Parsing = 'parsing';
    case Parsed = 'parsed';
    case Chunking = 'chunking';
    case Chunked = 'chunked';
    case Indexing = 'indexing';
    case Indexed = 'indexed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => '等待處理',
            self::Parsing => '解析中',
            self::Parsed => '解析完成',
            self::Chunking => '切段中',
            self::Chunked => '切段完成',
            self::Indexing => '建立索引中',
            self::Indexed => '索引完成',
            self::Failed => '處理失敗',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Uploaded => [self::Parsing, self::Failed],
            // Job 因暫時性錯誤重試時，文件停在 parsing 繼續處理，不算一次狀態轉換（見 DocumentParsingService）
            self::Parsing => [self::Parsed, self::Failed],
            // 回到 uploaded 代表重新處理：重新排入 Queue
            self::Parsed => [self::Chunking, self::Uploaded],
            self::Chunking => [self::Chunked, self::Failed],
            // chunked → chunking：調整切段參數後重新切段
            self::Chunked => [self::Chunking, self::Indexing, self::Uploaded],
            self::Indexing => [self::Indexed, self::Failed],
            // indexed → indexing：重建索引；indexed → chunking：重新切段（切完會自動重建索引）
            self::Indexed => [self::Indexing, self::Chunking, self::Uploaded],
            self::Failed => [self::Uploaded],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** 已解析完成、有頁面可以檢視與提問的狀態 */
    public function hasPages(): bool
    {
        return in_array($this, [self::Parsed, self::Chunking, self::Chunked, self::Indexing, self::Indexed], true);
    }

    /**
     * 可以刪除的狀態：處理中的文件不可刪除。否則 Job 可能在 Qdrant 清除之後才寫入 Points，留下孤兒。
     */
    public function canDelete(): bool
    {
        return in_array($this, [self::Parsed, self::Chunked, self::Indexed, self::Failed], true);
    }

    /** 可由使用者觸發重新處理的狀態 */
    public function canReprocess(): bool
    {
        return $this->canTransitionTo(self::Uploaded);
    }
}
