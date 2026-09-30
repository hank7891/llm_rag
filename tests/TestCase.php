<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 測試一律在獨立的 *_testing 資料庫執行，避免 DatabaseTransactions 以外的寫入碰到開發資料
        $database = config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with((string) $database, '_testing')) {
            throw new LogicException("Tests must run against a *_testing database, [{$database}] given.");
        }

        // 自動化測試不可呼叫真實 API：未被 Http::fake() 攔截的請求一律丟例外
        Http::preventStrayRequests();
    }

    /** RefreshDatabase 會執行 migrate:fresh（drop 全部表），本專案一律改用 DatabaseTransactions */
    protected function setUpTraits()
    {
        if (isset(class_uses_recursive(static::class)[RefreshDatabase::class])) {
            throw new LogicException('RefreshDatabase is forbidden; use DatabaseTransactions instead.');
        }

        return parent::setUpTraits();
    }
}
