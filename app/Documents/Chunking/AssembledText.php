<?php

namespace App\Documents\Chunking;

/**
 * 串接後的全文，以及每一頁在全文中的起訖字元位置（Offset Map）。
 * 位置以「字元」計算（mb_*），不是 byte。
 */
final readonly class AssembledText
{
    /**
     * @param  list<array{page: int, start: int, end: int}>  $pages  每頁在全文中的範圍，end 不含
     */
    public function __construct(
        public string $text,
        public array $pages,
    ) {}

    /**
     * 字元位置所在的頁碼。落在頁與頁之間的換行時，視為上一頁。
     */
    public function pageAt(int $offset): int
    {
        $page = $this->pages[0]['page'] ?? 1;

        foreach ($this->pages as $range) {
            if ($offset < $range['start']) {
                break;
            }

            $page = $range['page'];
        }

        return $page;
    }

    /**
     * 一段文字（起點含、終點不含）的起訖頁碼。
     *
     * @return array{0: int, 1: int}
     */
    public function pageRange(int $start, int $end): array
    {
        return [$this->pageAt($start), $this->pageAt(max($start, $end - 1))];
    }
}
