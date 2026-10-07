<?php

namespace App\Rag\Citation;

/**
 * 被移除的引用：標記原文、原因，以及編號（非數字時為 null）。
 */
final readonly class InvalidRef
{
    public function __construct(
        public string $raw,
        public InvalidReason $reason,
        public ?int $ref = null,
    ) {}
}
