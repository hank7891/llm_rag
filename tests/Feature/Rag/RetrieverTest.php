<?php

namespace Tests\Feature\Rag;

use App\Rag\Retrieval\RetrievalResult;
use App\Rag\Retrieval\RetrievedChunk;
use App\Rag\Retrieval\RetrieveOptions;
use App\Rag\Retrieval\RetrieverService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * RetrieverService 與 rag:search。Qdrant 以 Http::fake() 模擬，向量由 FakeEmbeddingProvider 產生（模型 fake）。
 */
class RetrieverTest extends TestCase
{
    private const QUERY = 'http://qdrant.test/collections/company_docs_fake/points/query';

    private const PAYLOAD = [
        'document_id' => 4, 'document_name' => '員工管理辦法.pdf', 'chunk_id' => 101, 'chunk_index' => 3, 'page_start' => 2, 'page_end' => 3,
        'section' => '第十二條', 'chunk_strategy' => 's', 'embedding_model' => 'fake', 'content' => '員工年度特別休假未休畢者，得遞延至次一年度。',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rag.qdrant.url', 'http://qdrant.test');
        config()->set('rag.retrieval.top_k', 7);
        config()->set('rag.retrieval.score_thresholds', ['fake' => 0.5]);
    }

    /** @param list<array<string, mixed>> $points */
    private function fakeQuery(array $points = []): void
    {
        Http::fake([self::QUERY => Http::response(['result' => ['points' => $points], 'status' => 'ok'])]);
    }

    private function retrieve(RetrieveOptions $options = new RetrieveOptions): RetrievalResult
    {
        return $this->app->make(RetrieverService::class)->retrieve('我的假沒休完怎麼辦？', $options);
    }

    public function test_model_threshold_and_top_k_come_from_config(): void
    {
        $this->fakeQuery();

        $this->retrieve();

        Http::assertSent(fn (Request $r) => $r['score_threshold'] === 0.5 && $r['limit'] === 7);
    }

    public function test_without_threshold_score_threshold_is_not_sent(): void
    {
        $this->fakeQuery();

        $result = $this->retrieve(new RetrieveOptions(applyThreshold: false));

        $this->assertNull($result->scoreThreshold);
        Http::assertSent(fn (Request $r) => ! isset($r['score_threshold']));
    }

    public function test_options_override_threshold_and_top_k(): void
    {
        $this->fakeQuery();

        $this->retrieve(new RetrieveOptions(topK: 3, scoreThreshold: 0.62));

        Http::assertSent(fn (Request $r) => $r['score_threshold'] === 0.62 && $r['limit'] === 3);
    }

    public function test_hits_are_mapped_to_retrieved_chunks(): void
    {
        $this->fakeQuery([['id' => 101, 'version' => 1, 'score' => 0.6773, 'payload' => self::PAYLOAD]]);

        $this->assertEquals(
            [new RetrievedChunk(101, 4, '員工管理辦法.pdf', '第十二條', 2, 3, '員工年度特別休假未休畢者，得遞延至次一年度。', 0.6773)],
            $this->retrieve()->chunks,
        );
    }

    public function test_nothing_above_threshold_means_no_candidates(): void
    {
        $this->fakeQuery();

        $this->assertFalse($this->retrieve()->hasCandidates());
    }

    public function test_model_without_threshold_is_rejected_before_searching(): void
    {
        config()->set('rag.retrieval.score_thresholds', []);
        $this->fakeQuery();

        try {
            $this->retrieve();
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('[fake] 尚未設定相關度門檻', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_search_command_lists_ranked_chunks(): void
    {
        $this->fakeQuery([['id' => 101, 'version' => 1, 'score' => 0.6773, 'payload' => self::PAYLOAD]]);

        $this->artisan('rag:search', ['query' => '我的假沒休完怎麼辦？'])
            ->expectsOutputToContain('門檻：> 0.5')
            ->expectsTable(['#', '分數', '檔名', 'section', '頁碼', '內容開頭'], [[1, '0.6773', '員工管理辦法.pdf', '第十二條', '2–3', self::PAYLOAD['content']]])
            ->assertSuccessful();
    }

    public function test_search_command_reports_no_candidates(): void
    {
        $this->fakeQuery();

        $this->artisan('rag:search', ['query' => '公司有提供員工宿舍嗎？'])->expectsOutputToContain('無候選')->assertSuccessful();
    }

    public function test_search_command_can_skip_threshold(): void
    {
        $this->fakeQuery();

        $this->artisan('rag:search', ['query' => '公司有提供員工宿舍嗎？', '--no-threshold' => true])->expectsOutputToContain('門檻：不套用')->assertSuccessful();
        Http::assertSent(fn (Request $r) => ! isset($r['score_threshold']));
    }
}
