<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('conversations', 'provider')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->string('provider', 50)->nullable()->after('id')->comment('建立對話時的回答 Provider（config/llm.php 的名稱）；同一段對話不可中途更換。Ch13 建立的對話為 null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('conversations', 'provider')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropColumn('provider');
            });
        }
    }
};
