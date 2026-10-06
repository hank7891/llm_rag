<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_query_logs', function (Blueprint $table) {
            $table->id();
            $table->text('question')->comment('使用者問題原文');
            $table->decimal('top1_score', 6, 4)->nullable()->comment('不論是否通過門檻的最高分（Cosine）；Collection 沒有資料時為 null');
            $table->decimal('score_threshold', 6, 4)->nullable()->comment('當次使用的相關度門檻，門檻調整後仍能比對');
            $table->unsignedSmallInteger('passed_count')->comment('通過門檻的 Chunk 數');
            $table->string('status_key', 40)->comment('回答狀態(enum-AnswerStatus)');
            $table->string('source_key', 20)->comment('提問來源(enum-QuerySource)：校準門檻時排除 eval 測試題');
            $table->boolean('llm_called')->comment('是否呼叫 LLM');
            $table->string('embedding_model', 100)->comment('檢索使用的 Embedding 模型');
            $table->string('provider', 50)->comment('Chat Provider 名稱（未呼叫 LLM 時為原本會使用的 Provider）');
            $table->string('model', 100)->nullable()->comment('實際回答的模型；未呼叫 LLM 時為 null');
            $table->unsignedInteger('input_tokens')->nullable()->comment('輸入 Token 數；未呼叫 LLM 時為 null');
            $table->unsignedInteger('output_tokens')->nullable()->comment('輸出 Token 數；未呼叫 LLM 時為 null');
            $table->unsignedInteger('retrieval_ms')->comment('檢索耗時（毫秒）');
            $table->unsignedInteger('llm_ms')->nullable()->comment('LLM 耗時（毫秒）；未呼叫 LLM 時為 null');
            $table->timestamp('created_at')->nullable();

            $table->index(['source_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_query_logs');
    }
};
