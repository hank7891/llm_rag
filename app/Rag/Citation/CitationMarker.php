<?php

namespace App\Rag\Citation;

/**
 * 回答中的一個方括號標記，例如 [1]、[1][3] 中的每一個、[1、3]、［１］、[資料不足]。
 * numbers 為 null 代表內容不是編號（non_numeric）。
 */
final readonly class CitationMarker
{
    /** @param list<int>|null $numbers */
    public function __construct(
        public string $raw,
        public int $offset,
        public ?array $numbers,
    ) {}
}
