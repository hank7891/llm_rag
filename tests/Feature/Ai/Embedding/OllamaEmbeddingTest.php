<?php

namespace Tests\Feature\Ai\Embedding;

use App\Ai\Chat\Providers\OllamaProvider;
use App\Ai\Embedding\DTO\EmbeddingInputType;
use App\Ai\Embedding\DTO\EmbeddingOptions;
use App\Ai\Embedding\EmbeddingModels;
use App\Ai\Embedding\Exceptions\EmbeddingCountMismatchException;
use App\Ai\Embedding\Exceptions\EmbeddingInputTooLongException;
use App\Ai\Exceptions\LlmClientException;
use App\Ai\Exceptions\LlmResponseFormatException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaEmbeddingTest extends TestCase
{
    private const URL = 'http://ollama.test/api/embed';

    private const QWEN_PREFIX = "Instruct: Given a web search query, retrieve relevant passages that answer the query\nQuery:";

    private function provider(int $batchSize = 16, int $numCtx = 2048): OllamaProvider
    {
        return new OllamaProvider([
            'base_url' => 'http://ollama.test', 'model' => 'qwen3:8b',
            'embedding_model' => 'bge-m3', 'embedding_batch_size' => $batchSize, 'embedding_num_ctx' => $numCtx,
            'num_ctx' => 8192, 'think' => false, 'truncate' => true, 'connect_timeout' => 5, 'timeout' => 120, 'stream_timeout' => 300,
        ], new EmbeddingModels(config('llm.embedding.models')));
    }

    /**
     * 依實測回應格式回傳：每個輸入一個向量，向量第一個值帶入輸入文字的長度，方便檢查順序。
     */
    private function fakeEmbed(?int $vectors = null, int $dimension = 4): void
    {
        Http::fake([self::URL => fn (Request $request) => Http::response([
            'model' => $request['model'],
            'embeddings' => array_map(
                fn (string $text) => [(float) mb_strlen($text), ...array_fill(0, $dimension - 1, 0.1)],
                array_slice($request['input'], 0, $vectors ?? count($request['input'])),
            ),
            'total_duration' => 1, 'load_duration' => 1,
            'prompt_eval_count' => count($request['input']) * 10,
        ])]);
    }

    private function embed(array $texts, EmbeddingInputType $type = EmbeddingInputType::Document, ?string $model = null, int $batchSize = 16)
    {
        return $this->provider($batchSize)->embed($texts, new EmbeddingOptions($type, $model));
    }

    // ---- payload ----

    public function test_payload_sends_input_as_array_with_truncate_disabled(): void
    {
        $this->fakeEmbed();

        $this->embed(['甲', '乙乙']);

        Http::assertSent(fn (Request $r) => [$r['model'], $r['input'], $r['truncate']] === ['bge-m3', ['甲', '乙乙'], false]);
    }

    public function test_num_ctx_and_num_batch_use_the_configured_value_for_every_model(): void
    {
        // 上限是記憶體與餘裕的取捨，由設定決定，不再取 min(模型上限, 8192)
        $this->fakeEmbed();

        $this->embed(['甲']);
        $this->embed(['甲'], model: 'qwen3-embedding:0.6b');

        $this->assertSame(
            [['num_ctx' => 2048, 'num_batch' => 2048], ['num_ctx' => 2048, 'num_batch' => 2048]],
            Http::recorded()->map(fn ($pair) => $pair[0]['options'])->all(),
        );
    }

    public function test_num_ctx_can_be_raised_for_longer_inputs(): void
    {
        $this->fakeEmbed();

        $this->provider(numCtx: 8192)->embed(['甲'], new EmbeddingOptions(EmbeddingInputType::Document));

        Http::assertSent(fn (Request $r) => $r['options'] === ['num_ctx' => 8192, 'num_batch' => 8192]);
    }

    public function test_config_default_is_2048(): void
    {
        $this->assertSame(2048, config('llm.ollama.embedding_num_ctx'));
    }

    public function test_bge_m3_gets_no_prefix(): void
    {
        $this->fakeEmbed();

        $this->embed(['我的假沒休完怎麼辦？'], EmbeddingInputType::Query);

        Http::assertSent(fn (Request $r) => $r['input'] === ['我的假沒休完怎麼辦？']);
    }

    public function test_qwen3_embedding_query_gets_instruction_prefix(): void
    {
        $this->fakeEmbed();

        $this->embed(['我的假沒休完怎麼辦？'], EmbeddingInputType::Query, 'qwen3-embedding:0.6b');

        Http::assertSent(fn (Request $r) => $r['input'] === [self::QWEN_PREFIX.'我的假沒休完怎麼辦？']);
    }

    public function test_qwen3_embedding_document_gets_no_prefix(): void
    {
        $this->fakeEmbed();

        $this->embed(['年度特別休假未休畢者，得遞延至次一年度。'], EmbeddingInputType::Document, 'qwen3-embedding:0.6b');

        Http::assertSent(fn (Request $r) => $r['input'] === ['年度特別休假未休畢者，得遞延至次一年度。']);
    }

    // ---- 分批與順序 ----

    public function test_input_over_batch_size_is_split_into_batches(): void
    {
        $this->fakeEmbed();

        $this->embed(['一', '二二', '三三三', '四四四四', '五五五五五'], batchSize: 2);

        $this->assertSame([['一', '二二'], ['三三三', '四四四四'], ['五五五五五']], Http::recorded()->map(fn ($pair) => $pair[0]['input'])->all());
    }

    public function test_batches_are_merged_in_original_order(): void
    {
        $this->fakeEmbed();

        $result = $this->embed(['一', '二二', '三三三', '四四四四', '五五五五五'], batchSize: 2);

        $this->assertSame([1.0, 2.0, 3.0, 4.0, 5.0], array_map(fn (array $v) => $v[0], $result->vectors));
    }

    public function test_usage_is_summed_across_batches(): void
    {
        $this->fakeEmbed();

        $this->assertSame(50, $this->embed(['一', '二', '三', '四', '五'], batchSize: 2)->inputTokens);
    }

    public function test_result_reports_model_and_dimension(): void
    {
        $this->fakeEmbed(dimension: 1024);

        $result = $this->embed(['甲']);

        $this->assertSame(['bge-m3', 1024], [$result->model, $result->dimension]);
    }

    // ---- 錯誤 ----

    public function test_vector_count_mismatch_in_a_batch_is_rejected(): void
    {
        $this->fakeEmbed(vectors: 1);

        $this->expectException(EmbeddingCountMismatchException::class);

        $this->embed(['甲', '乙']);
    }

    public function test_input_too_long_becomes_explicit_exception(): void
    {
        // 實測：truncate=false 且超過長度時，Ollama 回 HTTP 400，訊息不含 Token 數
        Http::fake([self::URL => Http::response(['error' => 'the input length exceeds the context length'], 400)]);

        $this->expectException(EmbeddingInputTooLongException::class);
        $this->expectExceptionMessage('Embedding input exceeds 2048 tokens for model [bge-m3]');

        $this->embed([str_repeat('中', 9000)]);
    }

    public function test_other_client_errors_are_not_reported_as_too_long(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'model "nope" not found, try pulling it first'], 404)]);

        try {
            $this->embed(['甲'], model: 'bge-m3');
            $this->fail('Expected LlmClientException.');
        } catch (LlmClientException $e) {
            $this->assertNotInstanceOf(EmbeddingInputTooLongException::class, $e);
        }
    }

    public function test_missing_embeddings_field_is_rejected(): void
    {
        Http::fake([self::URL => Http::response(['model' => 'bge-m3', 'prompt_eval_count' => 3])]);

        $this->expectException(LlmResponseFormatException::class);
        $this->expectExceptionMessage('[embeddings]');

        $this->embed(['甲']);
    }
}
