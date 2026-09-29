<?php

namespace App\Ai\Exceptions;

/**
 * 供應商回應 5xx（含 Anthropic 529 overloaded）。
 */
class LlmServerException extends LlmException {}
