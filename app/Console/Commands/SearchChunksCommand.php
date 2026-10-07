<?php

namespace App\Console\Commands;

use App\Rag\Retrieval\KeywordOnlyPolicy;
use App\Rag\Retrieval\RetrievalMode;
use App\Rag\Retrieval\RetrievedChunk;
use App\Rag\Retrieval\RetrieveOptions;
use App\Rag\Retrieval\RetrieverService;
use Illuminate\Console\Command;

/**
 * 以 Retriever 搜尋 Chunk（除錯用）。預設套用該模型的門檻；--no-threshold 觀察原始分數。
 */
class SearchChunksCommand extends Command
{
    protected $signature = 'rag:search {query : 問題}
        {--model= : Embedding 模型，未填使用目前設定的模型}
        {--limit= : 回傳筆數（Top-K），未填使用 rag.retrieval.top_k}
        {--threshold= : 覆寫相關度門檻}
        {--no-threshold : 不套用門檻，列出原始分數}
        {--mode= : 檢索模式 dense / keyword / hybrid，未填使用 rag.retrieval.mode}
        {--policy= : keyword_only_policy：exact_only / allow}';

    protected $description = '以問題搜尋 Chunk，列出排名、分數、檔名、條號、頁碼與內容開頭';

    public function handle(RetrieverService $retriever): int
    {
        $result = $retriever->retrieve($this->argument('query'), new RetrieveOptions(
            model: $this->option('model'),
            topK: $this->option('limit') === null ? null : (int) $this->option('limit'),
            scoreThreshold: $this->option('threshold') === null ? null : (float) $this->option('threshold'),
            applyThreshold: ! $this->option('no-threshold'),
            mode: $this->option('mode') === null ? null : RetrievalMode::from($this->option('mode')),
            keywordOnlyPolicy: $this->option('policy') === null ? null : KeywordOnlyPolicy::from($this->option('policy')),
        ));

        $this->line(sprintf('模式：%s　模型：%s　Top-K：%d　門檻：%s', $result->mode->value, $result->model, $result->topK, $result->scoreThreshold === null ? '不套用' : '> '.$result->scoreThreshold));

        if (! $result->hasCandidates()) {
            $this->warn($result->scoreThreshold === null
                ? '沒有任何結果（Collection 是空的，或沒有這個模型的 Points）。'
                : sprintf('無候選：Dense 最高分 %s 未超過門檻，也沒有精確命中（問答時直接回答「資料不足」）。', $result->topScore() === null ? '—' : sprintf('%.4f', $result->topScore())));

            return self::SUCCESS;
        }

        // Dense / 關鍵字欄為「名次（分數）」；Hybrid 時分數欄為 RRF 分數
        $this->table(['#', '分數', 'Dense', '關鍵字', '精確', '檔名', 'section', '頁碼', '內容開頭'], array_map(fn (int $rank, RetrievedChunk $chunk) => [
            $rank + 1,
            sprintf('%.4f', $chunk->score),
            $chunk->denseRank === null ? '—' : sprintf('%d（%.4f）', $chunk->denseRank, $chunk->denseScore),
            $chunk->keywordRank === null ? '—' : sprintf('%d（%.2f）', $chunk->keywordRank, $chunk->keywordScore),
            $chunk->exactMatch ? '✓' : '',
            $chunk->documentName,
            $chunk->section ?? '—',
            $chunk->pageStart === $chunk->pageEnd ? $chunk->pageStart : "{$chunk->pageStart}–{$chunk->pageEnd}",
            mb_substr(str_replace("\n", ' ', $chunk->content), 0, 80),
        ], array_keys($result->chunks), $result->chunks));

        return self::SUCCESS;
    }
}
