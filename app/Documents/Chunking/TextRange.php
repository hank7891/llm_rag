<?php

namespace App\Documents\Chunking;

/**
 * 全文中的一段範圍（字元位置，起點含、終點不含）。切段全程只操作位置、不重組文字，
 * 確保 Chunk 內容一定等於原文對應的切片，頁碼反查也才準確。
 */
final readonly class TextRange
{
    public function __construct(
        public int $start,
        public int $end,
        public ?string $section = null,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start;
    }

    public function slice(string $text): string
    {
        return mb_substr($text, $this->start, $this->length());
    }

    public function withBounds(int $start, int $end): self
    {
        return new self($start, $end, $this->section);
    }

    /** 去掉頭尾空白後的範圍；全是空白時回傳 null */
    public function trimmed(string $text): ?self
    {
        $slice = $this->slice($text);
        $leading = mb_strlen($slice) - mb_strlen(ltrim($slice));
        $trailing = mb_strlen($slice) - mb_strlen(rtrim($slice));

        if ($leading === mb_strlen($slice)) {
            return null;
        }

        return $this->withBounds($this->start + $leading, $this->end - $trailing);
    }
}
