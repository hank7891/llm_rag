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
        return $this->models[$model] ?? $this->models[self::canonical($model)]
            ?? throw new LogicException("Embedding model [{$model}] is not configured in config/llm.php (embedding.models).");
    }

    /**
     * 標準化的模型名稱（去掉 :latest）。寫入 Payload 的 embedding_model、推導 Collection 名稱、
     * 搜尋過濾都用這個值，否則 bge-m3 與 bge-m3:latest 會被當成不同的模型。
     */
    public static function canonical(string $model): string
    {
        return preg_replace('/:latest$/', '', $model);
    }
}
