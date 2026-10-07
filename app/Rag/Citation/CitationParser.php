<?php

namespace App\Rag\Citation;

/**
 * 找出回答中的引用標記。只處理方括號（半形 [] 或全形 ［］），內容最多 20 字；
 * 更長的方括號視為一般文字，不當成引用（例如引述條文名稱）。
 *
 * Ch09 七份結果實際出現的寫法：[1]～[5]、連寫的 [1][3][4][5]、[資料不足]。
 * [1, 3]、[1、3]、[1-3]、全形 ［１］ 依任務文件一併支援。
 */
class CitationParser
{
    private const PATTERN = '/[\[［]([^\[\]［］\n]{1,20})[\]］]/u';

    /** 編號之間的分隔：逗號、頓號；範圍：- – ~ */
    private const NUMBERS = '/^\d+(?:\s*(?:[,，、]|[-–~～－])\s*\d+)*$/u';

    /** @return list<CitationMarker> */
    public function parse(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(fn (array $m) => new CitationMarker(
            $m[0][0],
            mb_strlen(substr($text, 0, $m[0][1])), // PREG 回傳 byte 位置，換算成字元位置
            $this->numbers($m[1][0]),
        ), $matches);
    }

    /** @return list<int>|null */
    private function numbers(string $content): ?array
    {
        $content = trim(mb_convert_kana($content, 'a')); // 全形英數字轉半形：１ → 1

        if (preg_match(self::NUMBERS, $content) !== 1) {
            return null;
        }

        $numbers = [];
        preg_match_all('/(\d+)(?:\s*[-–~～－]\s*(\d+))?/u', $content, $parts, PREG_SET_ORDER);
        foreach ($parts as $part) {
            $from = (int) $part[1];
            $to = isset($part[2]) ? (int) $part[2] : $from;
            // 反向或過大的範圍（例如 [3-1]、[1-999]）不展開，只取起點
            array_push($numbers, ...($to >= $from && $to - $from < 20 ? range($from, $to) : [$from]));
        }

        return array_values(array_unique($numbers));
    }
}
