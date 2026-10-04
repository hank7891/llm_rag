<?php

namespace App\Ai\Embedding\Exceptions;

use InvalidArgumentException;

class InvalidEmbeddingInputException extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('Texts to embed must not be empty.');
    }

    public static function blankText(int|string $key): self
    {
        return new self(sprintf('Texts[%s] is empty or not a string.', $key));
    }
}
