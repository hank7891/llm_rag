<?php

namespace Tests\Support;

use App\Ai\Chat\Providers\FakeChatProvider;

/**
 * 測試專用的第二個 Provider，用來驗證「只換 provider 名稱，呼叫端程式碼不變」。
 */
class AltFakeChatProvider extends FakeChatProvider
{
    protected const REPLY_PREFIX = 'Alt: ';
}
