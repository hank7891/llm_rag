<?php

use App\Http\Controllers\Api\ChatController;
use Illuminate\Support\Facades\Route;

// 學習用 POC：尚未加上驗證機制，不可直接對外公開
Route::post('/ai/chat', [ChatController::class, 'chat']);
Route::post('/ai/chat/stream', [ChatController::class, 'stream']);
