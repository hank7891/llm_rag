<?php

namespace App\Ai\Chat\DTO;

/**
 * 一則對話訊息（內部格式）。
 */
final readonly class Message
{
    public function __construct(
        public Role $role,
        public string $content,
    ) {}
}
