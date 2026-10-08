<?php

namespace Tests\Feature\Ai\Rerank;

use App\Ai\Chat\Providers\GeminiProvider;
use App\Ai\Chat\Providers\OllamaProvider;
use App\Ai\Chat\Providers\OpenAIProvider;
use App\Ai\Exceptions\LlmConnectionException;
use App\Ai\Exceptions\LlmResponseFormatException;
use App\Ai\Exceptions\LlmTimeoutException;
use App\Ai\Rerank\Contracts\RerankProviderInterface;
use App\Ai\Rerank\DTO\RerankOptions;
use App\Ai\Rerank\DTO\RerankScore;
use App\Ai\Rerank\Exceptions\UnknownRerankProviderException;
use App\Ai\Rerank\Providers\CohereCompatibleRerankProvider;
use App\Ai\Rerank\Providers\FakeRerankProvider;
use App\Ai\Rerank\RerankService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CohereCompatibleRerankProvider 與 RerankService。回應格式依 Ch12 實測 llama-server 0.6.0 精簡而成。
 */
class RerankTest extends TestCase
{
    private const URL = 'http://rerank.test/v1/rerank';

    private const DOCUMENTS = ['識別證遺失補發 375 元', '特休未休可協商遞延至次一年度', '筆電開機密碼'];

    private function provider(): CohereCompatibleRerankProvider
    {
        return new CohereCompatibleRerankProvider(['name' => 'llamacpp', 'base_url' => 'http://rerank.test', 'model' => 'bge-reranker-v2-m3', 'timeout' => 3]);
    }

    /** @param list<array<string, mixed>> $results */
    private function fake(array $results): void
    {
        // 實測：results 依分數由高到低排序，不是送出順序；另含 usage、object 等欄位
        Http::fake([self::URL => Http::response(['model' => 'bge-reranker-v2-m3', 'object' => 'list', 'usage' => ['prompt_tokens' => 158], 'results' => $results])]);
    }

    public function test_request_uses_cohere_compatible_format(): void
    {
        $this->fake([['index' => 1, 'relevance_score' => 1.1], ['index' => 0, 'relevance_score' => -10.9], ['index' => 2, 'relevance_score' => -11.0]]);

        $this->provider()->rerank('特休沒休完可以延到明年嗎？', self::DOCUMENTS, new RerankOptions(topN: 2));

        Http::assertSent(fn (Request $r) => $r->url() === self::URL && $r->data() === [
            'model' => 'bge-reranker-v2-m3', 'query' => '特休沒休完可以延到明年嗎？', 'documents' => self::DOCUMENTS, 'top_n' => 2,
        ]);
    }

    public function test_scores_map_back_by_index_when_response_order_differs(): void
    {
        // 回傳順序打亂（不是依送出順序、也不是依分數）：一律以 index 對回 documents，結果依分數排序
        $this->fake([['index' => 2, 'relevance_score' => -11.0], ['index' => 0, 'relevance_score' => -10.9], ['index' => 1, 'relevance_score' => 1.1]]);

        $result = $this->provider()->rerank('特休', self::DOCUMENTS, new RerankOptions);

        $this->assertEquals([new RerankScore(1, 1.1), new RerankScore(0, -10.9), new RerankScore(2, -11.0)], $result->scores);
    }

    public function test_top_n_is_omitted_when_null(): void
    {
        $this->fake([['index' => 0, 'relevance_score' => 1.0]]);

        $this->provider()->rerank('特休', ['特休'], new RerankOptions);

        Http::assertSent(fn (Request $r) => ! array_key_exists('top_n', $r->data()));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidResponses(): array
    {
        return [
            'index 超出範圍' => [['results' => [['index' => 3, 'relevance_score' => 1.0]]]],
            '負數 index' => [['results' => [['index' => -1, 'relevance_score' => 1.0]]]],
            '重複 index' => [['results' => [['index' => 0, 'relevance_score' => 1.0], ['index' => 0, 'relevance_score' => 0.5]]]],
            '缺少分數' => [['results' => [['index' => 0]]]],
            '沒有 results' => [['model' => 'x']],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidResponses')]
    public function test_invalid_response_is_rejected(array $body): void
    {
        Http::fake([self::URL => Http::response($body)]);

        $this->expectException(LlmResponseFormatException::class);

        $this->provider()->rerank('特休', self::DOCUMENTS, new RerankOptions);
    }

    public function test_connection_refused_becomes_connection_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7', 0, new ConnectException('refused', new PsrRequest('POST', self::URL))));

        $this->expectException(LlmConnectionException::class);

        $this->provider()->rerank('特休', self::DOCUMENTS, new RerankOptions);
    }

    public function test_timeout_becomes_timeout_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28', 0, new NetworkTimeoutException('timed out', new PsrRequest('POST', self::URL))));

        $this->expectException(LlmTimeoutException::class);

        $this->provider()->rerank('特休', self::DOCUMENTS, new RerankOptions);
    }

    // ---- RerankService ----

    public function test_default_provider_is_fake_in_tests(): void
    {
        $result = $this->app->make(RerankService::class)->rerank('特休', ['筆電開機密碼', '特休未休可遞延']);

        $this->assertSame([1, 'fake'], [$result->scores[0]->index, $result->model]);
    }

    public function test_switching_provider_by_name_uses_configured_class(): void
    {
        $this->app->bind(CohereCompatibleRerankProvider::class, fn () => $this->provider());
        $this->fake([['index' => 0, 'relevance_score' => 1.0]]);

        $this->app->make(RerankService::class)->rerank('特休', ['特休'], provider: 'llamacpp');

        Http::assertSentCount(1);
    }

    public function test_empty_or_blank_documents_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(RerankService::class)->rerank('特休', ['特休', '  ']);
    }

    public function test_unknown_provider_lists_available_names(): void
    {
        $this->expectExceptionObject(UnknownRerankProviderException::named('cohere', ['llamacpp', 'fake']));

        $this->app->make(RerankService::class)->rerank('特休', ['特休'], provider: 'cohere');
    }

    public function test_chat_and_embedding_providers_do_not_implement_rerank(): void
    {
        $this->assertSame([false, false, false, true], [
            is_subclass_of(OllamaProvider::class, RerankProviderInterface::class),
            is_subclass_of(OpenAIProvider::class, RerankProviderInterface::class),
            is_subclass_of(GeminiProvider::class, RerankProviderInterface::class),
            is_subclass_of(FakeRerankProvider::class, RerankProviderInterface::class),
        ]);
    }
}
