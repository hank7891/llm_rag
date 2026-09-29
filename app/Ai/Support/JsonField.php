<?php

namespace App\Ai\Support;

use App\Ai\Exceptions\LlmResponseFormatException;
use Illuminate\Support\Arr;

/**
 * Tolerant Reader：只讀取需要的欄位，其餘忽略；必要欄位缺少或型別錯誤時丟出明確例外。
 */
final class JsonField
{
    /** 必要字串欄位；空字串合法，缺少、null 或非字串時丟例外。 */
    public static function string(string $provider, array $data, string $path): string
    {
        $value = Arr::get($data, $path);

        if (! is_string($value)) {
            throw new LlmResponseFormatException("[{$provider}] Missing or invalid string field [{$path}].");
        }

        return $value;
    }

    /** Token 數：缺少時補 0（Usage 不允許 null）；存在但非整數或為負數時丟例外。 */
    public static function tokenCount(string $provider, array $data, string $path): int
    {
        if (! Arr::has($data, $path)) {
            return 0;
        }

        $value = Arr::get($data, $path);

        if (! is_int($value) || $value < 0) {
            throw new LlmResponseFormatException("[{$provider}] Invalid token count field [{$path}].");
        }

        return $value;
    }
}
