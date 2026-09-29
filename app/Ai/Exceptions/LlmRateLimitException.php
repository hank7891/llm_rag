<?php

namespace App\Ai\Exceptions;

/**
 * 供應商回應 429（請求過於頻繁或額度不足）。
 */
class LlmRateLimitException extends LlmException {}
