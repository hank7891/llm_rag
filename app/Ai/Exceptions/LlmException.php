<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * 呼叫 LLM 供應商失敗的共同父類別。訊息與 log 不可包含 API Key。
 */
abstract class LlmException extends RuntimeException {}
