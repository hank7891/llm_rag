<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * 確認測試環境的防護：不會誤用真實 Provider，也不會送出真實 HTTP 請求。
 */
class TestEnvironmentTest extends TestCase
{
    public function test_default_chat_provider_is_fake(): void
    {
        $this->assertSame('fake', config('llm.chat.default'));
    }

    public function test_stray_http_requests_are_blocked(): void
    {
        $this->expectException(RuntimeException::class);

        Http::get('http://localhost:11434/api/tags');
    }
}
