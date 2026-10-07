<?php

namespace Tests\Feature\Rag;

use App\Models\RagQueryLog;
use App\Rag\Answer\QuerySource;
use App\Rag\Retrieval\RetrievalResult;
use App\Rag\Retrieval\RetrieverService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\Support\SeedsRetrievedChunks;
use Tests\TestCase;

/**
 * POST /api/knowledge/ask 與 rag:ask。Retriever 以 mock 取代，LLM 使用 FakeChatProvider。
 */
class KnowledgeAskTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsRetrievedChunks;

    protected function setUp(): void
    {
        parent::setUp();

        $chunks = $this->seedChunks([['第十二條 特別休假', '第十二條', 0.6651]]);
        $this->mock(RetrieverService::class, fn (MockInterface $mock) => $mock->shouldReceive('retrieve')->andReturn(new RetrievalResult($chunks, 'fake', 5, 0.59)));
    }

    public function test_api_returns_rag_answer(): void
    {
        $this->postJson('/api/knowledge/ask', ['question' => '我的特休沒休完怎麼辦？', 'provider' => 'fake'])
            ->assertOk()
            ->assertJson(['status' => 'answered', 'llm_called' => true, 'provider' => 'fake', 'references' => [['number' => 1, 'section' => '第十二條']], 'dropped_chunks' => 0,
                // FakeChatProvider 回顯 user 訊息，其中含有「[1] 第十二條…」，因此會被解析成引用 [1]
                'citations' => [['ref' => 1, 'label' => '員工管理辦法.pdf　第十二條　第 2 頁', 'section' => '第十二條']],
                'sources' => ['[1] 員工管理辦法.pdf　第十二條　第 2 頁'],
                'warnings' => ['invalid_refs' => [], 'uncited' => false]]);
    }

    public function test_api_logs_query_as_api_source(): void
    {
        $this->postJson('/api/knowledge/ask', ['question' => '我的特休沒休完怎麼辦？']);

        $this->assertSame(QuerySource::Api, RagQueryLog::sole()->source_key);
    }

    public function test_api_validates_question_and_provider(): void
    {
        $this->postJson('/api/knowledge/ask', ['provider' => 'anthropic'])->assertUnprocessable()->assertJsonValidationErrors(['question', 'provider']);
    }

    public function test_command_shows_context_sent_to_llm(): void
    {
        $this->artisan('rag:ask', ['question' => '我的特休沒休完怎麼辦？', '--provider' => 'fake', '--show-context' => true])
            ->expectsOutputToContain('你是公司內部知識助理。')
            ->expectsOutputToContain('[1] 第十二條 特別休假')
            ->expectsOutputToContain('status：answered')
            ->assertSuccessful();

        $this->assertSame(QuerySource::Cli, RagQueryLog::sole()->source_key);
    }
}
