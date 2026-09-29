<?php

namespace App\Ai\Exceptions;

/**
 * 已連上供應商，但超過等待時間仍未完成回應。
 */
class LlmTimeoutException extends LlmException {}
