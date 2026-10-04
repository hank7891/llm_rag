<?php

namespace App\Ai\Embedding\Exceptions;

use App\Ai\Exceptions\LlmClientException;

/**
 * 輸入超過 Embedding 模型的長度上限。以 truncate=false 讓供應商直接報錯，而不是靜默截斷。
 * 發生時通常代表 Chunk 的長度上限（config/rag.php）設得太大。
 */
class EmbeddingInputTooLongException extends LlmClientException {}
