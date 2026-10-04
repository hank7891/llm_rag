<?php

namespace App\Ai\Embedding\Exceptions;

use App\Ai\Exceptions\LlmResponseFormatException;

/**
 * 向量維度和設定的模型規格不同：Ch07 建立 Qdrant Collection 的 size 必須一致，提早在這裡擋下。
 */
class EmbeddingDimensionMismatchException extends LlmResponseFormatException
{
    public static function of(string $model, int $expected, int $actual): self
    {
        return new self(sprintf('Model [%s] should produce %d-dimensional vectors but produced %d.', $model, $expected, $actual));
    }
}
