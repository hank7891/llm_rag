<?php

namespace Tests\Unit\Rag;

use App\Rag\Search\SearchTextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SearchTextNormalizerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            '中文數字條號' => ['第十二條 特別休假', '第12條特別休假'],
            '十位數' => ['第二十五條', '第25條'],
            '只有十' => ['第十條', '第10條'],
            '百位數含零' => ['第一百零三條', '第103條'],
            '之一' => ['第三條之一', '第3條之1'],
            '阿拉伯數字加中文之一' => ['第7條之一', '第7條之1'],
            '條號中的空白' => ['第 12 條', '第12條'],
            '章' => ['第 3 章 附則', '第3章附則'],
            '全形英數與連字號（中文標點維持全形）' => ['代碼：ＡＢＣ－１２３', '代碼：abc123'],
            '英數之間的連字號與底線移除' => ['HRIS-3、IT_REQ-2026-0042', 'hris3、itreq20260042'],
            '中文之間的連字號保留' => ['勞-資', '勞-資'],
            '英文轉小寫、英文之間保留空白' => ['This Handbook applies', 'this handbook applies'],
            '中英之間與中文標點旁的空白' => ['申請系統代碼為 HR-2026 ，相關表單', '申請系統代碼為hr2026，相關表單'],
        ];
    }

    #[DataProvider('cases')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, (new SearchTextNormalizer)->normalize($input));
    }

    public function test_question_and_document_forms_of_article_number_are_identical(): void
    {
        $normalizer = new SearchTextNormalizer;

        $this->assertSame(
            [$normalizer->normalize('第十二條'), $normalizer->normalize('第 12 條')],
            [$normalizer->normalize('第12條'), $normalizer->normalize('第12條')],
        );
    }
}
