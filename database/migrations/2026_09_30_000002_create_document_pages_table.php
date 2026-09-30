<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete()->comment('關聯文件資料(documents)');
            $table->unsignedInteger('page_number')->comment('頁碼，從 1 開始，對應原檔頁碼');
            $table->mediumText('content')->comment('正規化後的頁面文字');
            $table->timestamps();

            // 同一份文件的頁碼不可重複：Job 重跑時若沒先刪舊頁面，會在這裡被擋下
            $table->unique(['document_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_pages');
    }
};
