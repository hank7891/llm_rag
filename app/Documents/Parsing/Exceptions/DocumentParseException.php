<?php

namespace App\Documents\Parsing\Exceptions;

use RuntimeException;

/**
 * 檔案本身無法解析（壞檔、不支援的格式或編碼、疑似掃描檔）。屬於永久性錯誤，Job 不應重試。
 * 訊息會顯示給使用者，須為可讀的中文說明。
 */
class DocumentParseException extends RuntimeException {}
