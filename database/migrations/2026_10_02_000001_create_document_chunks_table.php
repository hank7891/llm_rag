<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete()->comment('關聯文件資料(documents)');
            $table->unsignedInteger('chunk_index')->comment('Chunk 在文件中的順序，從 0 開始');
            $table->mediumText('content')->comment('Chunk 原文，不加任何前綴（Citation 顯示用）');
            $table->unsignedInteger('char_count')->comment('字元數');
            $table->unsignedInteger('token_count')->comment('以字元數粗估的 Token 數（係數見 config/rag.php）');
            $table->unsignedInteger('page_start')->comment('起始頁碼（對應 document_pages.page_number）');
            $table->unsignedInteger('page_end')->comment('結束頁碼，跨頁時大於 page_start');
            $table->string('section')->nullable()->comment('所在章節，例如「第二章 請假 / 第三條之一」；無結構文件為 null');
            $table->string('chunk_strategy', 100)->comment('切段策略與參數，例如 structure_v1:max600:ov15');
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
