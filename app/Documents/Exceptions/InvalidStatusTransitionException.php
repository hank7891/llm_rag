<?php

namespace App\Documents\Exceptions;

use App\Documents\DocumentStatus;
use LogicException;

class InvalidStatusTransitionException extends LogicException
{
    public static function between(int $documentId, DocumentStatus $from, DocumentStatus $to): self
    {
        return new self(sprintf('Document #%d cannot change status from [%s] to [%s].', $documentId, $from->value, $to->value));
    }
}
