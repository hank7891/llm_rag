<?php

namespace App\Ai\Exceptions;

/**
 * 連不上供應商（例如 Ollama 沒有啟動、DNS 失敗），與逾時區分。
 */
class LlmConnectionException extends LlmException {}
