<?php

namespace App\Rag\Citation;

/**
 * 一個被引用的來源。檔名、條號、頁碼一律由程式從 MySQL 查出，不使用 LLM 寫出的內容。
 * score 為檢索分數（MySQL 沒有，取自編號對照表）。
 */
final readonly class Citation
{
    public function __construct(
        public int $ref,
        public int $chunkId,
        public int $documentId,
        public string $documentName,
        public ?string $section,
        public int $pageStart,
        public int $pageEnd,
        public float $score,
    ) {}
}
