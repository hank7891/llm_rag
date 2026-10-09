<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete()->comment('關聯對話資料(conversations)');
            $table->string('role_key', 20)->comment('發言者(enum-ConversationRole)');
            $table->mediumText('content')->comment('使用者的原始問題，或助理的原始回答（含 [n]，組歷史時才移除）');
            $table->text('rewritten_question')->nullable()->comment('檢索用的改寫後問題；只有使用者訊息有值');
            $table->string('rewrite_status_key', 20)->nullable()->comment('改寫狀態(enum-RewriteStatus)；只有使用者訊息有值');
            $table->string('status_key', 40)->nullable()->comment('回答狀態(enum-AnswerStatus)；只有助理訊息有值');
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
