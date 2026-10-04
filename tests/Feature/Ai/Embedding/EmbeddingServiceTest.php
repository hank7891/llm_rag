<?php

namespace Tests\Feature\Ai\Embedding;

use App\Ai\Chat\Providers\FakeChatProvider;
use App\Ai\Embedding\DTO\EmbeddingInputType;
use App\Ai\Embedding\DTO\EmbeddingOptions;
use App\Ai\Embedding\DTO\EmbeddingResult;
use App\Ai\Embedding\EmbeddingService;
use App\Ai\Embedding\Exceptions\EmbeddingCountMismatchException;
use App\Ai\Embedding\Exceptions\EmbeddingDimensionMismatchException;
use App\Ai\Embedding\Exceptions\InvalidEmbeddingInputException;
use App\Ai\Embedding\Exceptions\UnknownEmbeddingProviderException;
use App\Ai\Embedding\Providers\FakeEmbeddingProvider;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    private FakeEmbeddingProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeEmbeddingProvider;
        $this->app->instance(FakeEmbeddingProvider::class, $this->fake);
    }

    private function service(): EmbeddingService
    {
        return $this->app->make(EmbeddingService::class);
    }

    private function documents(): EmbeddingOptions
    {
        return new EmbeddingOptions(EmbeddingInputType::Document);
    }

    /** 回傳指定結果的 Provider（模擬供應商回傳異常的資料） */
    private function providerReturning(EmbeddingResult $result): void
    {
        $this->app->instance(FakeEmbeddingProvider::class, new class($result) extends FakeEmbeddingProvider
        {
            public function __construct(private readonly EmbeddingResult $result) {}

            public function embed(array $texts, EmbeddingOptions $options): EmbeddingResult
            {
                return $this->result;
            }
        });
    }

    public function test_vectors_match_inputs_in_count_and_order(): void
    {
        $result = $this->service()->embed(['第一段', '第二段', '第三段'], $this->documents());

        $this->assertSame(
            [3, $this->fake->embed(['第二段'], $this->documents())->vectors[0]],
            [count($result->vectors), $result->vectors[1]],
        );
    }

    public function test_texts_and_options_reach_provider_unchanged(): void
    {
        $options = new EmbeddingOptions(EmbeddingInputType::Query, model: 'fake');

        $this->service()->embed(['我的假沒休完怎麼辦？'], $options);

        $this->assertSame([['我的假沒休完怎麼辦？'], $options], [$this->fake->lastTexts, $this->fake->lastOptions]);
    }

    public function test_same_text_always_gets_same_vector(): void
    {
        $this->assertSame(
            $this->service()->embed(['特休'], $this->documents())->vectors,
            $this->service()->embed(['特休'], $this->documents())->vectors,
        );
    }

    // ---- 輸入驗證 ----

    public function test_empty_input_is_rejected(): void
    {
        $this->expectException(InvalidEmbeddingInputException::class);

        $this->service()->embed([], $this->documents());
    }

    /** @return array<string, array{array<mixed>}> */
    public static function blankInputs(): array
    {
        return ['空字串' => [['第一段', '']], '只有空白' => [['   ']], '不是字串' => [['第一段', null]]];
    }

    #[DataProvider('blankInputs')]
    public function test_blank_text_is_rejected(array $texts): void
    {
        $this->expectException(InvalidEmbeddingInputException::class);

        $this->service()->embed($texts, $this->documents());
    }

    // ---- 數量與維度檢查 ----

    public function test_count_mismatch_is_rejected(): void
    {
        $this->providerReturning(new EmbeddingResult([array_fill(0, 8, 0.1)], 'fake', 8, 1));

        $this->expectException(EmbeddingCountMismatchException::class);
        $this->expectExceptionMessage('Expected 2 vectors but received 1');

        $this->service()->embed(['甲', '乙'], $this->documents());
    }

    public function test_dimension_mismatch_is_rejected(): void
    {
        $this->providerReturning(new EmbeddingResult([array_fill(0, 3, 0.1)], 'fake', 3, 1));

        $this->expectException(EmbeddingDimensionMismatchException::class);
        $this->expectExceptionMessage('should produce 8-dimensional vectors but produced 3');

        $this->service()->embed(['甲'], $this->documents());
    }

    public function test_model_name_with_latest_tag_matches_spec(): void
    {
        // Ollama 可能回傳 bge-m3:latest，規格表以 bge-m3 登記
        $this->providerReturning(new EmbeddingResult([array_fill(0, 1024, 0.1)], 'bge-m3:latest', 1024, 1));

        $this->assertSame('bge-m3:latest', $this->service()->embed(['甲'], $this->documents())->model);
    }

    public function test_unconfigured_model_is_rejected(): void
    {
        $this->providerReturning(new EmbeddingResult([[0.1]], 'unknown-model', 1, 1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Embedding model [unknown-model] is not configured');

        $this->service()->embed(['甲'], $this->documents());
    }

    // ---- 依名稱取得 Provider、依能力區分 ----

    public function test_unknown_provider_lists_available_names(): void
    {
        $this->expectException(UnknownEmbeddingProviderException::class);
        $this->expectExceptionMessageMatches('/Unknown embedding provider \[nope\]\. Available: .*\bfake\b/');

        $this->service()->embed(['甲'], $this->documents(), 'nope');
    }

    public function test_chat_only_provider_name_is_not_an_embedding_provider(): void
    {
        // openai 只登記在 chat.providers；embedding.providers 沒有它
        $this->expectException(UnknownEmbeddingProviderException::class);

        $this->service()->embed(['甲'], $this->documents(), 'openai');
    }

    public function test_chat_only_class_registered_by_mistake_is_rejected_at_binding(): void
    {
        // 只實作 ChatProviderInterface 的類別被誤登記：在取得 Provider 時就擋下，而不是等到呼叫 embed()
        config()->set('llm.embedding.providers.broken', FakeChatProvider::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement App\Ai\Embedding\Contracts\EmbeddingProviderInterface');

        $this->service()->embed(['甲'], $this->documents(), 'broken');
    }

    public function test_switching_provider_needs_no_caller_change(): void
    {
        config()->set('llm.embedding.providers.fake_alt', FakeEmbeddingProvider::class);
        $embed = fn (string $provider) => $this->service()->embed(['甲'], $this->documents(), $provider)->model;

        $this->assertSame(['fake', 'fake'], [$embed('fake'), $embed('fake_alt')]);
    }

    public function test_default_provider_is_fake_in_tests(): void
    {
        $this->assertSame('fake', config('llm.embedding.default'));
    }

    public function test_probe_command_reports_dimension_tokens_and_similarity(): void
    {
        $this->artisan('llm:embed-probe')
            ->expectsOutputToContain('模型：fake｜實際維度：8｜規格維度：8')
            ->expectsOutputToContain('我的假沒休完怎麼辦？')
            ->expectsOutputToContain('停車場收費標準')
            ->assertSuccessful();
    }

    public function test_probe_command_rejects_chat_only_provider(): void
    {
        $this->expectException(UnknownEmbeddingProviderException::class);

        $this->artisan('llm:embed-probe', ['--provider' => 'openai']);
    }

    public function test_usage_is_logged(): void
    {
        Log::spy();

        $this->service()->embed(['甲乙', '丙'], new EmbeddingOptions(EmbeddingInputType::Query));

        Log::shouldHaveReceived('info')->once()->with('llm.embedding', Mockery::on(fn (array $c) => [$c['provider'], $c['model'], $c['input_type'], $c['texts'], $c['input_tokens']]
            === ['fake', 'fake', 'query', 2, 3]));
    }
}
