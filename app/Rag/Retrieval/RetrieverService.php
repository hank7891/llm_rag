<?php

namespace App\Rag\Retrieval;

use App\Ai\Embedding\EmbeddingModels;
use App\Ai\Embedding\EmbeddingService;
use App\Rag\Search\ExactTermExtractor;
use App\Rag\Search\SearchTextNormalizer;
use App\Rag\VectorStore\SearchHit;
use App\Rag\VectorStore\VectorSearcher;
use App\Repositories\DocumentChunkRepository;
use App\Repositories\KeywordSearchRepository;
use RuntimeException;

/**
 * Retriever：輸入問題，輸出相關 Chunk（Top-K + 相關度門檻）。之後的 Hybrid Search、Reranker 都改這一層。
 */
class RetrieverService
{
    /** @param array<string, float> $scoreThresholds 模型名稱 → 門檻 */
    /** @param array<string, float> $scoreThresholds 模型名稱 → 門檻 */
    public function __construct(
        private readonly VectorSearcher $searcher,
        private readonly EmbeddingService $embedding,
        private readonly KeywordSearchRepository $keywords,
        private readonly ExactTermExtractor $exactTerms,
        private readonly SearchTextNormalizer $normalizer,
        private readonly DocumentChunkRepository $chunks,
        private readonly RankFusion $fusion,
        private readonly int $topK,
        private readonly array $scoreThresholds,
        private readonly RetrievalMode $mode,
        private readonly KeywordOnlyPolicy $keywordOnlyPolicy,
        private readonly int $denseCandidates,
        private readonly int $keywordCandidates,
        private readonly int $rrfK,
    ) {}

    /**
     * 三路檢索：Dense（套門檻）、一般關鍵字（NATURAL LANGUAGE MODE）、精確詞（BOOLEAN MODE 片語）。
     * RRF 只負責排序；是否有資料由「Dense 命中或精確命中」決定，一般關鍵字結果不能單獨讓問題進入 LLM。
     * 不套門檻（applyThreshold = false）時不做這個判斷，用來觀察原始排名。
     */
    public function retrieve(string $query, RetrieveOptions $options = new RetrieveOptions): RetrievalResult
    {
        $model = EmbeddingModels::canonical($options->model ?? $this->embedding->defaultModel());
        $mode = $options->mode ?? $this->mode;
        $policy = $options->keywordOnlyPolicy ?? $this->keywordOnlyPolicy;
        $topK = $options->topK ?? $this->topK;
        $threshold = $options->applyThreshold ? $options->scoreThreshold ?? $this->threshold($model) : null;

        $dense = [];
        $unfilteredTopScore = null;
        if ($mode !== RetrievalMode::Keyword) {
            $vector = $this->searcher->embedQuery($query, $model);
            // Dense 模式維持 Ch08 的行為（只取 Top-K）；Hybrid 先取較多候選再合併
            $hits = $this->searcher->searchVector($vector, $model, $mode === RetrievalMode::Dense ? $topK : max($topK, $this->denseCandidates), $threshold);
            $dense = array_column(array_map(fn (SearchHit $h) => ['id' => (int) $h->id, 'score' => $h->score], $hits), 'score', 'id');

            // 沒有候選時 Qdrant 只回傳空陣列，看不到被擋掉的最高分；用同一個向量再取 1 筆（不套門檻），供紀錄與校準門檻
            $unfilteredTopScore = $hits === [] && $threshold !== null
                ? ($this->searcher->searchVector($vector, $model, 1)[0] ?? null)?->score
                : null;
        }

        $keyword = [];
        $exact = [];
        if ($mode !== RetrievalMode::Dense) {
            // 建立索引與查詢必須經過同一個正規化，否則「第12條」對不上「第十二條」
            $normalized = $this->normalizer->normalize($query);
            $keyword = $this->keywords->natural($normalized, $this->keywordCandidates);
            $exact = $this->keywords->exact($this->exactTerms->extract($normalized), $this->keywordCandidates);
        }

        $eligible = array_keys($dense + $exact + ($policy === KeywordOnlyPolicy::Allow || $threshold === null ? $keyword : []));
        $denseTopScore = array_values($dense)[0] ?? $unfilteredTopScore;

        // 資料不足的判斷：Dense 命中與精確命中都沒有就是無候選（一般關鍵字命中再多也不算）
        $gated = $threshold !== null && $dense === [] && $exact === []
            && ! ($mode === RetrievalMode::Keyword && $policy === KeywordOnlyPolicy::Allow && $keyword !== []);
        if ($gated || $eligible === []) {
            return new RetrievalResult([], $model, $topK, $threshold, $unfilteredTopScore, $denseTopScore, $mode);
        }

        $rrf = $this->fusion->fuse(['dense' => array_keys($dense), 'keyword' => array_keys($keyword), 'exact' => array_keys($exact)], $this->rrfK);
        $ranked = $mode === RetrievalMode::Dense ? array_keys($dense) : array_values(array_intersect(array_keys($rrf), $eligible));
        $denseRanks = array_flip(array_keys($dense));
        $keywordRanks = array_flip(array_keys($keyword));

        // 內容與 Metadata 依 id 從 MySQL 取回（只取已建立索引的文件），先過濾再取 Top-K
        $rows = $this->chunks->findIndexed($ranked);
        $chunks = [];
        foreach ($ranked as $id) {
            if (count($chunks) === $topK || ! $rows->has($id)) {
                continue;
            }

            $row = $rows[$id];
            $chunks[] = new RetrievedChunk(
                $id, $row->document_id, $row->document->name, $row->section, $row->page_start, $row->page_end, $row->content,
                $mode === RetrievalMode::Dense ? $dense[$id] : $rrf[$id],
                isset($denseRanks[$id]) ? $denseRanks[$id] + 1 : null,
                $dense[$id] ?? null,
                isset($keywordRanks[$id]) ? $keywordRanks[$id] + 1 : null,
                $keyword[$id] ?? null,
                isset($exact[$id]),
                $mode === RetrievalMode::Dense ? null : $rrf[$id],
            );
        }

        return new RetrievalResult($chunks, $model, $topK, $threshold, $unfilteredTopScore, $denseTopScore, $mode);
    }

    /** 設定檔中該模型的門檻；沒有設定時為 null */
    public function configuredThreshold(string $model): ?float
    {
        return $this->scoreThresholds[EmbeddingModels::canonical($model)] ?? null;
    }

    private function threshold(string $model): float
    {
        return $this->configuredThreshold($model)
            ?? throw new RuntimeException("Embedding 模型 [{$model}] 尚未設定相關度門檻（config/rag.php 的 retrieval.score_thresholds）。");
    }
}
