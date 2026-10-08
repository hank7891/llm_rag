<?php

namespace App\Ai\Rerank\Exceptions;

use InvalidArgumentException;

class UnknownRerankProviderException extends InvalidArgumentException
{
    /** @param list<string> $available */
    public static function named(string $name, array $available): self
    {
        return new self(sprintf('Unknown rerank provider [%s]. Available: %s.', $name, implode(', ', $available)));
    }
}
