<?php

namespace App\Rag\VectorStore\Exceptions;

use RuntimeException;

/**
 * 呼叫 Qdrant 失敗（連線失敗或 HTTP 錯誤）。訊息包含 Qdrant 回傳的錯誤內容。
 */
class QdrantException extends RuntimeException {}
