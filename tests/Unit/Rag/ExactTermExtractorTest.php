<?php

namespace Tests\Unit\Rag;

use App\Rag\Search\ExactTermExtractor;
use App\Rag\Search\SearchTextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 從「使用者原本輸入的問題」抽出精確詞：先經 SearchTextNormalizer（與建立索引相同），再抽詞。
 * 抽出的詞必須完整，否則 BOOLEAN MODE 片語查詢會落空或命中錯誤的段落。
 */
class ExactTermExtractorTest extends TestCase
{
    /** @return array<string, array{string, list<string>}> */
    public static function questions(): array
    {
        return [
            '阿拉伯數字的之一（a03）' => ['第3條之1 是什麼假？', ['第3條之1']],
            '中文數字的之一' => ['第三條之一的內容是什麼？', ['第3條之1']],
            '阿拉伯數字加中文之一' => ['第7條之一規定了什麼？', ['第7條之1']],
            '條號不能只抽出一半' => ['第3條之1和第3條有什麼不同？', ['第3條之1', '第3條']],
            '英數編號含單一數字段（c06）' => ['HRIS-3 是什麼系統？', ['hris3']],
            '多段連字號編號' => ['IT-REQ-2026-0042 要找誰審核？', ['itreq20260042']],
            '全形編號' => ['ＢＵＧ－２０２６－０００１８３ 要附上什麼？', ['bug2026000183']],
            '條號與空白（a05）' => ['第 6 條規定人資部門要在幾天內提供出勤紀錄？', ['第6條']],
            '不存在的編號仍會抽出（n02）' => ['系統代碼 HR-9999 是什麼？', ['hr9999']],
            '人名不是精確詞（p01）' => ['林怡君負責什麼業務？', []],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('questions')]
    public function test_terms_are_extracted_completely_from_raw_question(string $question, array $expected): void
    {
        $this->assertSame($expected, (new ExactTermExtractor)->extract((new SearchTextNormalizer)->normalize($question)));
    }
}
