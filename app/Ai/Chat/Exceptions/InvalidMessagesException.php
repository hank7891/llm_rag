<?php

namespace App\Ai\Chat\Exceptions;

use InvalidArgumentException;

class InvalidMessagesException extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('Messages must not be empty.');
    }

    public static function notMessage(int|string $key): self
    {
        return new self(sprintf('Messages[%s] is not an instance of Message.', $key));
    }

    public static function lastNotUser(): self
    {
        return new self('The last message must have the user role.');
    }
}
