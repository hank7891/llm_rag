<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rag_query_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('rag_query_logs', 'reranked')) {
                $table->boolean('reranked')->default(false)->after('retrieval_ms')->comment('是否完成第二階段重排；關閉、無候選或降級時為 false');
            }
            if (! Schema::hasColumn('rag_query_logs', 'rerank_degraded')) {
                $table->boolean('rerank_degraded')->default(false)->after('reranked')->comment('Reranker 失敗、逾時而退回原排序');
            }
            if (! Schema::hasColumn('rag_query_logs', 'rerank_ms')) {
                $table->unsignedInteger('rerank_ms')->nullable()->after('rerank_degraded')->comment('重排耗時（毫秒，包含在 retrieval_ms 內）；沒有呼叫 Reranker 時為 null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rag_query_logs', function (Blueprint $table) {
            foreach (['reranked', 'rerank_degraded', 'rerank_ms'] as $column) {
                if (Schema::hasColumn('rag_query_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
