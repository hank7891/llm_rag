<?php

namespace App\Ai\Embedding\DTO;

/**
 * Embedding 的結果：vectors 與輸入的 texts 一一對應、順序相同。
 */
final readonly class EmbeddingResult
{
    /**
     * @param  list<list<float>>  $vectors
     * @param  string  $model  實際產生向量的模型
     * @param  int  $inputTokens  整批輸入的 Token 數（供應商未回傳時為 0）
     */
    public function __construct(
        public array $vectors,
        public string $model,
        public int $dimension,
        public int $inputTokens,
    ) {}
}
