<?php

namespace Tests\Unit\Documents\Chunking;

use App\Documents\Chunking\RecursiveSplitter;
use App\Documents\Chunking\TextRange;
use PHPUnit\Framework\TestCase;

/**
 * 以「1 個字元 = 1 個 Token」估算，讓預期值容易人工驗算。
 */
class RecursiveSplitterTest extends TestCase
{
    private function splitter(int $max = 20, int $overlap = 6, int $min = 3): RecursiveSplitter
    {
        return new RecursiveSplitter($max, $overlap, $min, fn (string $s) => mb_strlen($s));
    }

    /** @return list<string> */
    private function split(string $text, ?RecursiveSplitter $splitter = null): array
    {
        $ranges = ($splitter ?? $this->splitter())->split($text, new TextRange(0, mb_strlen($text)));

        return array_map(fn (TextRange $r) => rtrim($r->slice($text)), $ranges);
    }

    public function test_short_text_is_not_split(): void
    {
        $this->assertSame(['短短一句話。'], $this->split('短短一句話。'));
    }

    public function test_every_chunk_is_within_max(): void
    {
        $text = str_repeat('甲乙丙丁戊己庚。', 10)."\n\n".str_repeat('一二三四五六七八九十', 5);

        foreach ($this->split($text) as $chunk) {
            $this->assertLessThanOrEqual(20, mb_strlen($chunk));
        }
    }

    public function test_paragraphs_are_packed_without_overlap(): void
    {
        // 單元包含後面的空行分隔（9 + 2 字）：甲 + 乙 = 22 > 20，甲單獨成段；乙 + 丙 = 20 剛好裝得下。
        // 段落之間不重疊：第二段不會帶入甲的內容
        $this->assertSame(['甲甲甲甲甲甲甲甲。', "乙乙乙乙乙乙乙乙。\n\n丙丙丙丙丙丙丙丙。"], $this->split("甲甲甲甲甲甲甲甲。\n\n乙乙乙乙乙乙乙乙。\n\n丙丙丙丙丙丙丙丙。"));
    }

    public function test_forced_split_inside_paragraph_overlaps_by_whole_sentence(): void
    {
        // 單一段落 25 字超過上限：依句子切開，前 4 句剛好 20 字；下一段開頭重疊上一段的最後一句（5 字 ≤ 6）
        $this->assertSame(['甲甲甲甲。乙乙乙乙。丙丙丙丙。丁丁丁丁。', '丁丁丁丁。戊戊戊戊。'], $this->split('甲甲甲甲。乙乙乙乙。丙丙丙丙。丁丁丁丁。戊戊戊戊。'));
    }

    public function test_no_overlap_when_last_sentence_exceeds_overlap_budget(): void
    {
        // 最後一句 8 字 > 重疊上限 6：不重疊，也不跳過它去拿更前面的短句
        $this->assertSame(['甲甲。乙乙乙乙乙乙乙。', '丙丙丙丙丙丙丙丙丙。'], $this->split('甲甲。乙乙乙乙乙乙乙。丙丙丙丙丙丙丙丙丙。', $this->splitter(max: 12)));
    }

    public function test_heading_stays_with_its_long_body(): void
    {
        // 標題段落很短、正文段落太長：標題要和正文開頭在同一個 Chunk，不能單獨成段
        $chunks = $this->split("第四條\n\n".str_repeat('甲乙丙丁戊。', 6));

        $this->assertStringStartsWith("第四條\n\n甲乙丙丁戊。", $chunks[0]);
    }

    public function test_heading_stays_with_body_that_fits_alone_but_not_with_heading(): void
    {
        // 正文段落 20 字（本身沒超過上限），但加上標題（5 字 < 最小長度 8）就超過：標題不能單獨收成一段
        $chunks = $this->split("第四條\n\n甲乙丙丁戊己。庚辛壬癸子丑。寅卯辰巳午。", $this->splitter(min: 8));

        $this->assertStringStartsWith("第四條\n\n甲乙丙丁戊己。", $chunks[0]);
    }

    public function test_short_tail_is_merged_without_duplicating_overlap(): void
    {
        // 尾段只有「丁。」2 字 < 最小長度 3：以原文範圍併回前一段，不會重複文字
        $chunks = $this->split('甲甲甲甲甲甲甲甲。乙乙乙乙乙乙乙乙。丙丙丙丙丙丙丙丙。丁。');

        $this->assertSame('丁。', mb_substr(end($chunks), -2));
        $this->assertSame(1, substr_count(implode('|', $chunks), '丁'));
    }

    public function test_text_without_punctuation_falls_back_to_character_split(): void
    {
        $chunks = $this->split(str_repeat('一二三四五六七八九十', 5));

        $this->assertSame([true, true], [count($chunks) >= 3, max(array_map('mb_strlen', $chunks)) <= 20]);
    }

    public function test_character_split_always_makes_progress(): void
    {
        // 重疊預算比上限還大的極端設定，也不能產生無限迴圈或只有重疊的 Chunk
        $text = str_repeat('一二三四五六七八九十', 3);
        $ranges = $this->splitter(max: 10, overlap: 50, min: 0)->splitFixed($text, new TextRange(0, mb_strlen($text)));

        $this->assertSame(range(0, count($ranges) - 1), array_keys(array_unique(array_map(fn (TextRange $r) => $r->start, $ranges))));
    }

    public function test_fixed_split_ignores_sentences(): void
    {
        $text = '甲甲甲甲。乙乙乙乙。丙丙丙丙。丁丁丁丁。戊戊戊戊。';
        $ranges = $this->splitter()->splitFixed($text, new TextRange(0, mb_strlen($text)));

        $this->assertSame(20, $ranges[0]->length());
    }
}
