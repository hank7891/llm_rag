<?php

namespace App\Ai\Embedding\Exceptions;

use InvalidArgumentException;

class UnknownEmbeddingProviderException extends InvalidArgumentException
{
    /** @param list<string> $available */
    public static function named(string $name, array $available): self
    {
        return new self(sprintf('Unknown embedding provider [%s]. Available: %s.', $name, implode(', ', $available)));
    }
}
