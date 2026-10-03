<?php

namespace App\Documents\Chunking;

/**
 * 依頁碼把逐頁文字串成全文（頁與頁之間以換行連接），並記錄每頁在全文中的位置。
 * 切段在全文上進行，才能讓跨頁的條文成為同一個 Chunk；再用 Offset Map 反查頁碼。
 */
class PageTextAssembler
{
    private const PAGE_SEPARATOR = "\n";

    /**
     * @param  array<int, string>  $pages  頁碼 → 內容（會依頁碼排序）
     */
    public function assemble(array $pages): AssembledText
    {
        ksort($pages);

        $text = '';
        $ranges = [];

        foreach ($pages as $pageNumber => $content) {
            if ($text !== '') {
                $text .= self::PAGE_SEPARATOR;
            }

            $start = mb_strlen($text);
            $text .= $content;
            $ranges[] = ['page' => $pageNumber, 'start' => $start, 'end' => mb_strlen($text)];
        }

        // 空白頁沒有任何字元，不參與反查（否則會吃掉下一頁的起點）
        $ranges = array_values(array_filter($ranges, fn (array $r) => $r['end'] > $r['start']));

        return new AssembledText($text, $ranges);
    }
}
