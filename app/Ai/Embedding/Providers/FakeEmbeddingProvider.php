<?php

namespace App\Ai\Embedding\Providers;

use App\Ai\Embedding\Contracts\EmbeddingProviderInterface;
use App\Ai\Embedding\DTO\EmbeddingOptions;
use App\Ai\Embedding\DTO\EmbeddingResult;

/**
 * 不呼叫任何模型的 Provider：依文字內容產生固定、可重現的單位向量，並記錄收到的參數供測試檢查。
 * 相同文字一定得到相同向量；不同文字的向量不同，但數值沒有語意。
 */
class FakeEmbeddingProvider implements EmbeddingProviderInterface
{
    public const MODEL = 'fake';

    public const DIMENSION = 8;

    /** @var list<string>|null */
    public ?array $lastTexts = null;

    public ?EmbeddingOptions $lastOptions = null;

    public function embed(array $texts, EmbeddingOptions $options): EmbeddingResult
    {
        $this->lastTexts = $texts;
        $this->lastOptions = $options;

        return new EmbeddingResult(
            vectors: array_map(fn (string $text) => $this->vector($text), $texts),
            model: self::MODEL,
            dimension: self::DIMENSION,
            inputTokens: array_sum(array_map('mb_strlen', $texts)),
        );
    }

    /** @return list<float> */
    private function vector(string $text): array
    {
        $raw = array_map(fn (int $i) => sin(crc32($text) + $i), range(0, self::DIMENSION - 1));
        $norm = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $raw)));

        return array_map(fn (float $v) => $v / $norm, $raw);
    }
}
