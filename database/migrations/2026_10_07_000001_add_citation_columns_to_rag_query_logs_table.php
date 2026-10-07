<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rag_query_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('rag_query_logs', 'citation_count')) {
                $table->unsignedSmallInteger('citation_count')->default(0)->after('llm_called')->comment('合法引用數（MySQL 查得到的來源）');
            }
            if (! Schema::hasColumn('rag_query_logs', 'invalid_ref_count')) {
                $table->unsignedSmallInteger('invalid_ref_count')->default(0)->after('citation_count')->comment('被移除的引用標記數（超出範圍、非數字、來源不存在、資料不足的回答）');
            }
            if (! Schema::hasColumn('rag_query_logs', 'uncited')) {
                $table->boolean('uncited')->default(false)->after('invalid_ref_count')->comment('已回答但沒有任何合法引用');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rag_query_logs', function (Blueprint $table) {
            foreach (['citation_count', 'invalid_ref_count', 'uncited'] as $column) {
                if (Schema::hasColumn('rag_query_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
