<?php

namespace App\Ai\Exceptions;

/**
 * 回應缺少必要欄位、型別錯誤，或串流在結束訊號前中斷。
 */
class LlmResponseFormatException extends LlmException {}
