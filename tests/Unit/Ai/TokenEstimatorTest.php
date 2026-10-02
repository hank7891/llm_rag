<?php

namespace Tests\Unit\Ai;

use App\Ai\Support\TokenEstimator;
use PHPUnit\Framework\TestCase;

class TokenEstimatorTest extends TestCase
{
    public function test_chinese_is_about_three_quarters_token_per_char(): void
    {
        $this->assertSame(750, TokenEstimator::estimate(str_repeat('中', 1000)));
    }

    public function test_english_is_about_three_tenths_token_per_char(): void
    {
        $this->assertSame(300, TokenEstimator::estimate(str_repeat('a', 1000)));
    }

    public function test_mixed_text_adds_both_parts(): void
    {
        $this->assertSame(4, TokenEstimator::estimate('特休HR-2026'));
    }

    public function test_empty_text_is_zero(): void
    {
        $this->assertSame(0, TokenEstimator::estimate(''));
    }
}
