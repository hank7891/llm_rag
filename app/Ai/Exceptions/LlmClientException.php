<?php

namespace App\Ai\Exceptions;

/**
 * 供應商回應 4xx（429 除外），通常是設定錯誤，例如 API Key 無效、模型名稱不存在。
 */
class LlmClientException extends LlmException {}
