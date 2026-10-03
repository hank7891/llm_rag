<?php

namespace Tests\Unit\Documents\Chunking;

use App\Documents\Chunking\PageTextAssembler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PageTextAssemblerTest extends TestCase
{
    public function test_pages_are_joined_in_page_order_with_newline(): void
    {
        $assembled = (new PageTextAssembler)->assemble([2 => '第二頁', 1 => '第一頁']);

        $this->assertSame("第一頁\n第二頁", $assembled->text);
    }

    public function test_offset_map_records_character_positions(): void
    {
        $assembled = (new PageTextAssembler)->assemble([1 => '甲乙丙', 2 => '丁戊']);

        $this->assertSame([['page' => 1, 'start' => 0, 'end' => 3], ['page' => 2, 'start' => 4, 'end' => 6]], $assembled->pages);
    }

    /** @return array<string, array{int, int}> 全文「甲乙丙\n丁戊」 */
    public static function offsets(): array
    {
        return [
            '第一頁的第一個字' => [0, 1],
            '第一頁的最後一個字' => [2, 1],
            '頁與頁之間的換行（視為上一頁）' => [3, 1],
            '第二頁的第一個字' => [4, 2],
            '第二頁的最後一個字' => [5, 2],
        ];
    }

    #[DataProvider('offsets')]
    public function test_page_at_offset(int $offset, int $page): void
    {
        $this->assertSame($page, (new PageTextAssembler)->assemble([1 => '甲乙丙', 2 => '丁戊'])->pageAt($offset));
    }

    public function test_range_spanning_two_pages(): void
    {
        $this->assertSame([1, 2], (new PageTextAssembler)->assemble([1 => '甲乙丙', 2 => '丁戊'])->pageRange(1, 6));
    }

    public function test_range_ending_exactly_at_page_end_stays_on_that_page(): void
    {
        // 終點不含：[0, 3) 是「甲乙丙」，不應被算成跨到第 2 頁
        $this->assertSame([1, 1], (new PageTextAssembler)->assemble([1 => '甲乙丙', 2 => '丁戊'])->pageRange(0, 3));
    }

    public function test_blank_page_is_skipped_in_lookup(): void
    {
        $assembled = (new PageTextAssembler)->assemble([1 => '甲', 2 => '', 3 => '乙']);

        $this->assertSame(["甲\n\n乙", 3], [$assembled->text, $assembled->pageAt(3)]);
    }
}
