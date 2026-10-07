<?php

namespace App\Rag\Citation;

/**
 * 引用處理的結果。answer 為移除不合規標記後的回答；citations 依編號排序、每個編號一筆。
 * uncited：status 為已回答，但沒有任何合法引用。
 */
final readonly class CitationResult
{
    /**
     * @param  list<Citation>  $citations
     * @param  list<InvalidRef>  $invalidRefs
     */
    public function __construct(
        public string $answer,
        public array $citations,
        public array $invalidRefs,
        public bool $uncited,
    ) {}
}
