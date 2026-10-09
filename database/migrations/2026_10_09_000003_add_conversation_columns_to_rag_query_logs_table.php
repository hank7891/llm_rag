<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rag_query_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('rag_query_logs', 'conversation_id')) {
                // 不設外鍵：刪除對話時保留問答紀錄（紀錄用於校準門檻）
                $table->unsignedBigInteger('conversation_id')->nullable()->after('id')->comment('關聯對話資料(conversations)；單輪問答為 null');
            }
            if (! Schema::hasColumn('rag_query_logs', 'rewritten_question')) {
                $table->text('rewritten_question')->nullable()->after('question')->comment('檢索用的改寫後問題（question 為使用者的原始問題）');
            }
            if (! Schema::hasColumn('rag_query_logs', 'rewrite_status_key')) {
                $table->string('rewrite_status_key', 20)->nullable()->after('rewritten_question')->comment('改寫狀態(enum-RewriteStatus)');
            }
            if (! Schema::hasColumn('rag_query_logs', 'rewrite_ms')) {
                $table->unsignedInteger('rewrite_ms')->nullable()->after('retrieval_ms')->comment('改寫耗時（毫秒）；沒有呼叫改寫 LLM 時為 null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rag_query_logs', function (Blueprint $table) {
            foreach (['conversation_id', 'rewritten_question', 'rewrite_status_key', 'rewrite_ms'] as $column) {
                if (Schema::hasColumn('rag_query_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
