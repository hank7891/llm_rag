<?php

namespace App\Documents;

/**
 * 文件處理狀態與合法的狀態轉換（State Machine）。
 * indexing / indexed 於 Ch06 起使用。
 */
enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
    case Parsing = 'parsing';
    case Parsed = 'parsed';
    case Indexing = 'indexing';
    case Indexed = 'indexed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => '等待處理',
            self::Parsing => '解析中',
            self::Parsed => '解析完成',
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
            self::Parsed => [self::Indexing, self::Uploaded],
            self::Indexing => [self::Indexed, self::Failed],
            self::Indexed, self::Failed => [self::Uploaded],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** 可由使用者觸發重新處理的狀態 */
    public function canReprocess(): bool
    {
        return $this->canTransitionTo(self::Uploaded);
    }
}
