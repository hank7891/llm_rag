<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_chunks', 'search_text')) {
            Schema::table('document_chunks', function (Blueprint $table) {
                $table->mediumText('search_text')->nullable()->after('content')->comment('關鍵字搜尋用的正規化文字（SearchTextNormalizer），content 保持原文');
            });
        }

        // 中文沒有空格分詞，必須用 ngram parser（ngram_token_size 由 MySQL 啟動參數決定，見 compose.yaml）
        if (! Schema::hasIndex('document_chunks', 'document_chunks_search_text_fulltext')) {
            DB::statement('ALTER TABLE document_chunks ADD FULLTEXT INDEX document_chunks_search_text_fulltext (search_text) WITH PARSER ngram');
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('document_chunks', 'document_chunks_search_text_fulltext')) {
            Schema::table('document_chunks', fn (Blueprint $table) => $table->dropIndex('document_chunks_search_text_fulltext'));
        }

        if (Schema::hasColumn('document_chunks', 'search_text')) {
            Schema::table('document_chunks', fn (Blueprint $table) => $table->dropColumn('search_text'));
        }
    }
};
