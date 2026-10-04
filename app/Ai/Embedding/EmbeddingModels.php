<?php

namespace App\Ai\Embedding;

use LogicException;

/**
 * Embedding 模型規格表（config/llm.php 的 embedding.models）。EmbeddingService 用來檢查維度，
 * Provider 用來決定前綴與長度上限。
 */
final readonly class EmbeddingModels
{
    /**
     * @param  array<string, array{dimension: int, max_tokens: int, query_prefix: string, document_prefix: string}>  $models
     */
    public function __construct(private array $models) {}

    /**
     * Ollama 回傳的模型名稱可能帶 :latest（例如 bge-m3:latest），比對時去掉。
     *
     * @return array{dimension: int, max_tokens: int, query_prefix: string, document_prefix: string}
     */
    public function spec(string $model): array
    {
        return $this->models[$model] ?? $this->models[preg_replace('/:latest$/', '', $model)]
            ?? throw new LogicException("Embedding model [{$model}] is not configured in config/llm.php (embedding.models).");
    }
}
