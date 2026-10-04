<?php

namespace Tests\Unit\Ai;

use App\Ai\Embedding\Support\VectorMath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VectorMathTest extends TestCase
{
    /** @return array<string, array{list<float>, list<float>, float}> */
    public static function pairs(): array
    {
        return [
            '方向相同' => [[1.0, 2.0], [2.0, 4.0], 1.0],
            '垂直（無關）' => [[1.0, 0.0], [0.0, 1.0], 0.0],
            '方向相反' => [[1.0, 1.0], [-1.0, -1.0], -1.0],
            '45 度' => [[1.0, 0.0], [1.0, 1.0], 0.7071],
        ];
    }

    #[DataProvider('pairs')]
    public function test_cosine_similarity(array $a, array $b, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, VectorMath::cosine($a, $b), 0.0001);
    }

    public function test_dimension_mismatch_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VectorMath::cosine([1.0, 0.0], [1.0]);
    }
}
