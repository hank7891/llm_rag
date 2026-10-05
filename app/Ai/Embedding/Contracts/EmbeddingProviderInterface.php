<?php

namespace App\Ai\Embedding\Contracts;

use App\Ai\Embedding\DTO\EmbeddingOptions;
use App\Ai\Embedding\DTO\EmbeddingResult;

/**
 * Embedding 能力介面，和 ChatProviderInterface 分開：不支援 Embedding 的供應商不實作，
 * 而不是用一個永遠丟例外的 embed() 假裝符合介面（教學文件與 Ch01 的設計決定）。
 *
 * 實作者負責：依 inputType 與模型規格加上前綴、依供應商的限制分批、維持輸出順序。
 */
interface EmbeddingProviderInterface
{
    /** @param list<string> $texts */
    public function embed(array $texts, EmbeddingOptions $options): EmbeddingResult;

    /** EmbeddingOptions::$model 為 null 時使用的模型（Ch07：用來判斷「目前線上使用的 Collection」） */
    public function defaultEmbeddingModel(): string;
}
