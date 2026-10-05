<?php

namespace App\Rag\VectorStore;

use App\Ai\Embedding\EmbeddingModels;

/**
 * 由 Embedding 模型名稱推導 Collection 名稱：前綴 + "_" + 模型名稱（非英數字轉底線、轉小寫）。
 * 例：bge-m3 → company_docs_bge_m3；qwen3-embedding:0.6b → company_docs_qwen3_embedding_0_6b
 */
class CollectionResolver
{
    public function __construct(private readonly string $prefix) {}

    public function name(string $model): string
    {
        // Ollama 回傳的模型名稱可能帶 :latest，視為同一個模型，否則會多出一個 Collection
        $model = EmbeddingModels::canonical($model);

        return $this->prefix.'_'.trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($model)), '_');
    }

    /** 是否為本專案的 Collection（前綴相符） */
    public function owns(string $collection): bool
    {
        return str_starts_with($collection, $this->prefix.'_');
    }
}
