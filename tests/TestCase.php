<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 自動化測試不可呼叫真實 API：未被 Http::fake() 攔截的請求一律丟例外
        Http::preventStrayRequests();
    }
}
