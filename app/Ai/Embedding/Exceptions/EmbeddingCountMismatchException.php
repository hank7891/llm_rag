<?php

namespace App\Ai\Embedding\Exceptions;

use App\Ai\Exceptions\LlmResponseFormatException;

/**
 * 回傳的向量數量和輸入的段數不同：繼續使用的話，向量會配錯 Chunk，後面所有引用都會錯。
 */
class EmbeddingCountMismatchException extends LlmResponseFormatException
{
    public static function of(string $provider, int $expected, int $actual): self
    {
        return new self(sprintf('[%s] Expected %d vectors but received %d.', $provider, $expected, $actual));
    }
}
