<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/document');

// 學習用 POC：尚未加上登入機制，只在內部使用
Route::controller(DocumentController::class)->prefix('document')->name('documents.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/upload', 'create')->name('create');
    Route::post('/upload', 'store')->name('store');
    Route::get('/{document}', 'show')->whereNumber('document')->name('show');
    Route::post('/{document}/reprocess', 'reprocess')->whereNumber('document')->name('reprocess');
});
