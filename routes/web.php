<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\KnowledgeChatController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/document');

// 學習用 POC：尚未加上登入機制，只在內部使用
Route::controller(DocumentController::class)->prefix('document')->name('documents.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/upload', 'create')->name('create');
    Route::get('/statuses', 'statuses')->name('statuses');
    Route::post('/upload', 'store')->name('store');
    Route::get('/{document}', 'show')->whereNumber('document')->name('show');
    Route::post('/{document}/reprocess', 'reprocess')->whereNumber('document')->name('reprocess');
    Route::post('/{document}/reindex', 'reindex')->whereNumber('document')->name('reindex');
    Route::delete('/{document}', 'destroy')->whereNumber('document')->name('destroy');
});

Route::get('/knowledge/chat', KnowledgeChatController::class)->name('knowledge.chat');
