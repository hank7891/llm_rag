<?php

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DocumentAskController;
use App\Http\Controllers\Api\KnowledgeAskController;
use Illuminate\Support\Facades\Route;

// 學習用 POC：尚未加上驗證機制，不可直接對外公開
Route::post('/ai/chat', [ChatController::class, 'chat']);
Route::post('/ai/chat/stream', [ChatController::class, 'stream']);

Route::post('/documents/{document}/ask', DocumentAskController::class)->whereNumber('document');

Route::post('/knowledge/ask', KnowledgeAskController::class);
