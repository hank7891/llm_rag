<?php

namespace App\Repositories;

use App\Documents\DocumentStatus;
use Illuminate\Support\Facades\DB;

/**
 * MySQL FULLTEXT（ngram）查詢 document_chunks.search_text。只取 status = indexed 的文件。
 * 傳入的文字必須已經過 SearchTextNormalizer，與建立索引時一致。
 *
 * 注意：InnoDB FULLTEXT 看不到尚未提交的資料，DatabaseTransactions 的測試查不到交易中新增的 Chunk（Ch11 實測）。
 */
class KeywordSearchRepository
{
    /**
     * 一般關鍵字：NATURAL LANGUAGE MODE，依相關度排序。只參與排名，不單獨決定有無資料。
     *
     * @return array<int, float> chunk id → FULLTEXT 相關度，由高到低
     */
    public function natural(string $normalizedQuery, int $limit): array
    {
        return $this->search($normalizedQuery, 'NATURAL LANGUAGE MODE', $limit);
    }

    /**
     * 精確詞：BOOLEAN MODE 片語查詢，任一詞命中即算。每個詞包在雙引號內：
     * 否則「bug-2026-000183」的「-」會被解讀成「排除 2026」。
     *
     * @param  list<string>  $terms
     * @return array<int, float>
     */
    public function exact(array $terms, int $limit): array
    {
        $expression = implode(' ', array_map(fn (string $t) => '"'.str_replace(['"', '\\'], ' ', $t).'"', $terms));

        return $terms === [] ? [] : $this->search($expression, 'BOOLEAN MODE', $limit);
    }

    /** @return array<int, float> */
    private function search(string $against, string $mode, int $limit): array
    {
        $rows = DB::select(
            "SELECT c.id, MATCH(c.search_text) AGAINST (? IN {$mode}) AS score
             FROM document_chunks c JOIN documents d ON d.id = c.document_id
             WHERE d.status_key = ? AND MATCH(c.search_text) AGAINST (? IN {$mode})
             ORDER BY score DESC, c.id LIMIT ?",
            [$against, DocumentStatus::Indexed->value, $against, $limit],
        );

        return array_column(array_map(fn ($r) => ['id' => (int) $r->id, 'score' => (float) $r->score], $rows), 'score', 'id');
    }
}
