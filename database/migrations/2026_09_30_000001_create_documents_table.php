<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('上傳時的原始檔名');
            $table->string('mime_type', 100)->comment('以 finfo 偵測的實際 MIME 類型');
            $table->string('path', 500)->comment('私有 Disk 內的儲存路徑');
            $table->unsignedBigInteger('size')->comment('檔案大小(bytes)');
            $table->char('sha256', 64)->index()->comment('檔案內容雜湊，用於辨識重複上傳');
            $table->unsignedInteger('page_count')->nullable()->comment('解析後的頁數，解析完成前為 null');
            $table->string('status_key', 20)->index()->comment('處理狀態(enum-DocumentStatus)');
            $table->text('error_message')->nullable()->comment('處理失敗的原因');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
