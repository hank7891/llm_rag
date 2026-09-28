<?php

namespace App\Ai\Chat\Exceptions;

use InvalidArgumentException;

class UnknownChatProviderException extends InvalidArgumentException
{
    /** @param list<string> $available */
    public static function named(string $name, array $available): self
    {
        return new self(sprintf(
            'Unknown chat provider [%s]. Available: %s.',
            $name,
            implode(', ', $available),
        ));
    }
}
