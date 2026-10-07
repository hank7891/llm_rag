<?php

namespace Tests\Feature\Rag;

use App\Documents\DocumentStatus;
use App\Models\Document;
use App\Rag\Retrieval\KeywordOnlyPolicy;
use App\Rag\Retrieval\RankFusion;
use App\Rag\Retrieval\RetrievalMode;
use App\Rag\Retrieval\RetrievalResult;
use App\Rag\Retrieval\RetrievedChunk;
use App\Rag\Retrieval\RetrieveOptions;
use App\Rag\Retrieval\RetrieverService;
use App\Rag\Search\ExactTermExtractor;
use App\Repositories\KeywordSearchRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsRetrievedChunks;
use Tests\TestCase;

/**
 * Hybrid 檢索（Ch11）。Qdrant 以 Http::fake() 模擬；關鍵字搜尋以 mock 取代：
 * InnoDB FULLTEXT 看不到交易中尚未提交的資料，真正的 SQL 在 fulltext 群組測試（KeywordSearchTest）。
 */
class HybridRetrievalTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsRetrievedChunks;

    private const QUERY = 'http://qdrant.test/collections/company_docs_fake/points/query';

    /** @var list<int> 四段 Chunk 的 id：A、B、C、D */
    private array $ids;

    /** @var list<string> */
    private array $naturalQueries = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rag.qdrant.url', 'http://qdrant.test');
        config()->set('rag.retrieval.score_thresholds', ['fake' => 0.59]);
        config()->set('rag.retrieval.mode', 'hybrid');
        config()->set('rag.retrieval.top_k', 5);

        $chunks = $this->seedChunks([['A 第十二條', '第十二條', 0], ['B 第四條', '第四條', 0], ['C hr-2026', '第十二條', 0], ['D 人資部門', '第一條', 0]]);
        Document::whereKey($chunks[0]->documentId)->update(['status_key' => DocumentStatus::Indexed]);
        $this->ids = array_map(fn (RetrievedChunk $c) => $c->chunkId, $chunks);
    }

    /**
     * @param  array<int, float>  $dense  索引（0～3）→ Cosine（已通過門檻的才會回傳）
     * @param  array<int, float>  $natural  索引 → FULLTEXT 分數
     * @param  array<int, float>  $exact  索引 → FULLTEXT 分數
     */
    private function fake(array $dense = [], array $natural = [], array $exact = []): void
    {
        $byId = fn (array $map) => collect($map)->mapWithKeys(fn (float $score, int $i) => [$this->ids[$i] => $score])->all();
        $points = collect($dense)->map(fn (float $score, int $i) => ['id' => $this->ids[$i], 'version' => 1, 'score' => $score, 'payload' => []])->values()->all();
        Http::fake([self::QUERY => fn (Request $r) => Http::response(['result' => ['points' => isset($r['score_threshold']) ? $points : [['id' => $this->ids[0], 'score' => 0.5]]]])]);

        $this->mock(KeywordSearchRepository::class, function (MockInterface $mock) use ($byId, $natural, $exact) {
            $mock->shouldReceive('natural')->andReturnUsing(function (string $query) use ($byId, $natural) {
                $this->naturalQueries[] = $query;

                return $byId($natural);
            });
            $mock->shouldReceive('exact')->andReturnUsing(fn (array $terms) => $terms === [] ? [] : $byId($exact));
        });
    }

    private function retrieve(string $query = '特休怎麼算？', ?KeywordOnlyPolicy $policy = null, ?RetrievalMode $mode = null): RetrievalResult
    {
        return $this->app->make(RetrieverService::class)->retrieve($query, new RetrieveOptions(mode: $mode, keywordOnlyPolicy: $policy));
    }

    /** @return list<int> 以 0～3 表示的結果順序 */
    private function order(RetrievalResult $result): array
    {
        return array_map(fn (RetrievedChunk $c) => array_search($c->chunkId, $this->ids, true), $result->chunks);
    }

    // ---- RRF ----

    public function test_rrf_example_from_handout(): void
    {
        // #101：Dense 第 1、Keyword 第 3；#205：只有 Keyword 第 1；#088：只有 Dense 第 2
        $scores = (new RankFusion)->fuse(['dense' => [101, 88], 'keyword' => [205, 7, 101]], 60);

        $this->assertSame(
            [101 => round(1 / 61 + 1 / 63, 6), 205 => round(1 / 61, 6), 88 => round(1 / 62, 6), 7 => round(1 / 62, 6)],
            array_map(fn (float $s) => round($s, 6), $scores),
        );
    }

    // ---- 精確詞 ----

    /** @return array<string, array{string, list<string>}> */
    public static function exactTerms(): array
    {
        return [
            '條號' => ['第12條在講什麼？', ['第12條']],
            '條之一' => ['第3條之1的內容', ['第3條之1']],
            '編號（正規化後已移除連字號）' => ['bug2026000183 是什麼', ['bug2026000183']],
            '英數混合代號' => ['inv887231 的狀態', ['inv887231']],
            '引號字串' => ['什麼是「內部服務台」？', ['內部服務台']],
            '一般問題沒有精確詞' => ['我的假沒休完怎麼辦？', []],
            '純數字不是編號' => ['事假 10 天還能請幾天', []],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('exactTerms')]
    public function test_exact_terms_are_extracted(string $normalizedQuery, array $expected): void
    {
        $this->assertSame($expected, (new ExactTermExtractor)->extract($normalizedQuery));
    }

    // ---- 資料不足的判斷 ----

    public function test_keyword_hits_alone_do_not_pass_the_gate(): void
    {
        // 「規定」「怎麼」這類常見二字詞一定會命中一般關鍵字，不能單獨讓問題進入 LLM
        $this->fake(natural: [3 => 12.4, 1 => 8.0]);

        $this->assertSame([false, false], [
            $this->retrieve(policy: KeywordOnlyPolicy::ExactOnly)->hasCandidates(),
            $this->retrieve(policy: KeywordOnlyPolicy::Allow)->hasCandidates(),
        ]);
    }

    public function test_exact_match_passes_the_gate_without_dense(): void
    {
        $this->fake(natural: [2 => 9.0], exact: [2 => 15.0]);

        $result = $this->retrieve('HR-2026 是什麼的代碼？');

        $this->assertSame([[2], true, null], [$this->order($result), $result->chunks[0]->exactMatch, $result->chunks[0]->denseRank]);
    }

    // ---- keyword_only_policy ----

    public function test_exact_only_policy_excludes_keyword_only_chunks(): void
    {
        $this->fake(dense: [0 => 0.66], natural: [3 => 12.4, 0 => 5.0]);

        $this->assertSame([0], $this->order($this->retrieve(policy: KeywordOnlyPolicy::ExactOnly)));
    }

    public function test_allow_policy_lets_keyword_only_chunks_in(): void
    {
        $this->fake(dense: [0 => 0.66], natural: [3 => 12.4, 0 => 5.0]);

        $this->assertSame([0, 3], $this->order($this->retrieve(policy: KeywordOnlyPolicy::Allow)));
    }

    // ---- 合併 ----

    public function test_chunks_found_by_both_rank_first_and_debug_fields_are_kept(): void
    {
        $this->fake(dense: [1 => 0.70, 0 => 0.65], natural: [0 => 12.0, 3 => 9.0], exact: [0 => 20.0]);

        $result = $this->retrieve('第十二條', KeywordOnlyPolicy::ExactOnly);
        $top = $result->chunks[0];

        $this->assertSame(
            [[0, 1], 2, 0.65, 1, 12.0, true, round(1 / 62 + 1 / 61 + 1 / 61, 6), 0.70],
            [$this->order($result), $top->denseRank, $top->denseScore, $top->keywordRank, $top->keywordScore, $top->exactMatch, round($top->rrfScore, 6), $result->topScore()],
        );
    }

    public function test_query_is_normalized_before_keyword_search(): void
    {
        $this->fake(dense: [0 => 0.66]);

        $this->retrieve('第 十二 條 在講什麼？');

        $this->assertSame(['第12條在講什麼？'], $this->naturalQueries);
    }

    public function test_chunks_of_documents_not_indexed_are_excluded(): void
    {
        $this->fake(dense: [0 => 0.70], exact: [0 => 20.0]);
        Document::whereKey(Document::query()->latest('id')->value('id'))->update(['status_key' => DocumentStatus::Indexing]);

        $this->assertFalse($this->retrieve('第12條')->hasCandidates());
    }

    public function test_keyword_mode_does_not_call_qdrant(): void
    {
        $this->fake(exact: [2 => 15.0]);

        $result = $this->retrieve('HR-2026', mode: RetrievalMode::Keyword);

        $this->assertSame([[2], null], [$this->order($result), $result->topScore()]);
        Http::assertNothingSent();
    }

    public function test_dense_mode_ignores_keyword_search(): void
    {
        $this->fake(dense: [1 => 0.70], exact: [2 => 15.0]);

        $this->assertSame([1], $this->order($this->retrieve('HR-2026', mode: RetrievalMode::Dense)));
    }
}
