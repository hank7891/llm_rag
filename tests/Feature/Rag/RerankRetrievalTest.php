<?php

namespace Tests\Feature\Rag;

use App\Ai\Exceptions\LlmTimeoutException;
use App\Ai\Rerank\DTO\RerankOptions;
use App\Ai\Rerank\DTO\RerankResult;
use App\Ai\Rerank\DTO\RerankScore;
use App\Ai\Rerank\Providers\FakeRerankProvider;
use App\Documents\DocumentStatus;
use App\Models\Document;
use App\Rag\Retrieval\RetrievalResult;
use App\Rag\Retrieval\RetrievedChunk;
use App\Rag\Retrieval\RetrieveOptions;
use App\Rag\Retrieval\RetrieverService;
use App\Repositories\KeywordSearchRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\Support\SeedsRetrievedChunks;
use Tests\TestCase;

/**
 * Hybrid + Reranker（Ch12）。Qdrant 以 Http::fake() 模擬、關鍵字搜尋以 mock 取代（同 HybridRetrievalTest）；
 * Reranker 使用可指定分數的 Fake Provider。
 */
class RerankRetrievalTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsRetrievedChunks;

    private const QUERY = 'http://qdrant.test/collections/company_docs_fake/points/query';

    /** @var list<int> 五段 Chunk 的 id：0～4 */
    private array $ids;

    private ScriptedRerankProvider $reranker;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rag.qdrant.url', 'http://qdrant.test');
        config()->set('rag.retrieval.score_thresholds', ['fake' => 0.59]);
        config()->set('rag.retrieval.mode', 'hybrid');
        config()->set('rag.retrieval.top_k', 2);
        config()->set('rag.rerank.enabled', true);
        config()->set('rag.rerank.candidates', 4);

        $chunks = $this->seedChunks([['段落0', '第一條', 0], ['段落1', '第二條', 0], ['段落2', '第三條', 0], ['段落3 HR-2026', '第十二條', 0], ['段落4', '第五條', 0]]);
        Document::whereKey($chunks[0]->documentId)->update(['status_key' => DocumentStatus::Indexed]);
        $this->ids = array_map(fn (RetrievedChunk $c) => $c->chunkId, $chunks);

        $this->reranker = new ScriptedRerankProvider;
        $this->app->instance(FakeRerankProvider::class, $this->reranker);
    }

    /**
     * @param  list<int>  $dense  依 Dense 名次排列的索引（皆通過門檻）
     * @param  list<int>  $exact  精確命中的索引
     */
    private function fake(array $dense = [], array $exact = []): void
    {
        $points = array_map(fn (int $i) => ['id' => $this->ids[$i], 'version' => 1, 'score' => 0.7 - $i / 100, 'payload' => []], $dense);
        Http::fake([self::QUERY => fn (Request $r) => Http::response(['result' => ['points' => isset($r['score_threshold']) ? $points : []]])]);

        $byId = fn (array $indexes) => collect($indexes)->mapWithKeys(fn (int $i, int $rank) => [$this->ids[$i] => 20.0 - $rank])->all();
        $this->mock(KeywordSearchRepository::class, function (MockInterface $mock) use ($byId, $exact) {
            $mock->shouldReceive('natural')->andReturn([]);
            $mock->shouldReceive('exact')->andReturnUsing(fn (array $terms) => $terms === [] ? [] : $byId($exact));
        });
    }

    private function retrieve(string $query = '特休怎麼算？', RetrieveOptions $options = new RetrieveOptions): RetrievalResult
    {
        return $this->app->make(RetrieverService::class)->retrieve($query, $options);
    }

    /** @return list<int> 以 0～4 表示的結果順序 */
    private function order(RetrievalResult $result): array
    {
        return array_map(fn (RetrievedChunk $c) => array_search($c->chunkId, $this->ids, true), $result->chunks);
    }

    public function test_disabled_rerank_keeps_first_stage_order_and_does_not_call_reranker(): void
    {
        $this->fake(dense: [0, 1, 2]);

        $result = $this->retrieve(options: new RetrieveOptions(rerank: false));

        $this->assertSame([[0, 1], false, []], [$this->order($result), $result->reranked, $this->reranker->calls]);
    }

    public function test_candidates_are_reranked_then_cut_to_top_k(): void
    {
        $this->reranker->scores = ['段落0' => -5.0, '段落1' => 1.0, '段落2' => 3.0];
        $this->fake(dense: [0, 1, 2]);

        $result = $this->retrieve();
        $top = $result->chunks[0];

        $this->assertSame(
            [[2, 1], true, 3, 1, 3.0, ['段落0', '段落1', '段落2']],
            [$this->order($result), $result->reranked, $top->retrievalRank, $top->rerankRank, $top->rerankScore, $this->reranker->calls[0]['documents']],
        );
    }

    public function test_first_stage_takes_rerank_candidates(): void
    {
        $this->fake(dense: [0, 1, 2]);

        $this->retrieve();

        // Hybrid 的 Dense 候選數為 max(top_k, rerank_candidates, dense_candidates)
        Http::assertSent(fn (Request $r) => isset($r['score_threshold']) && $r['limit'] === 20);
        $this->assertCount(3, $this->reranker->calls[0]['documents']);
    }

    public function test_only_first_candidates_are_reranked_and_rest_keep_first_stage_order(): void
    {
        // 評估時 Top-K（5）大於候選數（2）：前 2 筆重排，第 3 筆之後維持原順序接在後面
        $this->reranker->scores = ['段落0' => -1.0, '段落1' => 2.0];
        $this->fake(dense: [0, 1, 2, 4]);

        $result = $this->retrieve(options: new RetrieveOptions(topK: 5, rerankCandidates: 2));

        $this->assertSame([[1, 0, 2, 4], ['段落0', '段落1']], [$this->order($result), $this->reranker->calls[0]['documents']]);
    }

    public function test_no_candidates_does_not_call_reranker(): void
    {
        $this->fake();

        $result = $this->retrieve('公司有提供員工宿舍嗎？');

        $this->assertSame([false, []], [$result->hasCandidates(), $this->reranker->calls]);
    }

    public function test_exact_match_is_kept_even_when_reranker_ranks_it_last(): void
    {
        // 段落3 是精確命中（HR-2026），Reranker 給最低分：keep_exact 時仍保留在 Top-2
        $this->reranker->scores = ['段落0' => 2.0, '段落1' => 3.0, '段落3 HR-2026' => -9.0];
        $this->fake(dense: [0, 1], exact: [3]);

        $result = $this->retrieve('HR-2026 是什麼？');

        $this->assertSame([[1, 3], true], [$this->order($result), $result->chunks[1]->exactMatch]);
    }

    public function test_exact_match_can_be_dropped_when_keep_exact_is_off(): void
    {
        $this->reranker->scores = ['段落0' => 2.0, '段落1' => 3.0, '段落3 HR-2026' => -9.0];
        $this->fake(dense: [0, 1], exact: [3]);

        $result = $this->retrieve('HR-2026 是什麼？', new RetrieveOptions(rerankKeepExact: false));

        $this->assertSame([1, 0], $this->order($result));
    }

    public function test_reranker_failure_degrades_to_first_stage_order(): void
    {
        Log::spy();
        $this->reranker->fail = true;
        $this->fake(dense: [0, 1, 2]);

        $result = $this->retrieve();

        $this->assertSame([[0, 1], false, true, null], [$this->order($result), $result->reranked, $result->rerankDegraded, $result->chunks[0]->rerankRank]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'rerank.degraded')->once();
    }

    public function test_prefix_metadata_adds_document_name_and_section(): void
    {
        $this->fake(dense: [0]);

        $this->retrieve(options: new RetrieveOptions(rerankPrefixMetadata: true));

        $this->assertSame(['員工管理辦法.pdf 第一條：段落0'], $this->reranker->calls[0]['documents']);
    }

    public function test_min_score_experiment_drops_low_scores_but_keeps_exact_matches(): void
    {
        config()->set('rag.rerank.min_score', 0.0);
        $this->reranker->scores = ['段落0' => -1.0, '段落1' => 2.0, '段落3 HR-2026' => -9.0];
        $this->fake(dense: [0, 1], exact: [3]);

        $this->assertSame([1, 3], $this->order($this->retrieve('HR-2026 是什麼？')));
    }
}

/**
 * 依段落內容指定分數的 Fake Reranker；沒有指定的段落得 0 分。fail 為 true 時模擬逾時。
 */
class ScriptedRerankProvider extends FakeRerankProvider
{
    /** @var array<string, float> */
    public array $scores = [];

    public bool $fail = false;

    public function rerank(string $query, array $documents, RerankOptions $options): RerankResult
    {
        $this->calls[] = ['query' => $query, 'documents' => $documents, 'options' => $options];

        if ($this->fail) {
            throw new LlmTimeoutException('[fake] Request timed out.');
        }

        $scores = array_map(fn (string $d, int $i) => new RerankScore($i, $this->scores[$d] ?? 0.0), $documents, array_keys($documents));
        usort($scores, fn (RerankScore $a, RerankScore $b) => [$b->score, $a->index] <=> [$a->score, $b->index]);

        return new RerankResult($scores, 'fake', 12);
    }
}
