<?php

namespace App\Repositories;

use App\Documents\Chunking\ChunkDraft;
use App\Documents\DocumentStatus;
use App\Models\DocumentChunk;
use App\Rag\Search\SearchTextNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DocumentChunkRepository
{
    public function __construct(private readonly SearchTextNormalizer $searchText = new SearchTextNormalizer) {}

    /** @return Collection<int, DocumentChunk> 依 chunk_index 排序 */
    public function forDocument(int $documentId): Collection
    {
        return DocumentChunk::where('document_id', $documentId)->orderBy('chunk_index')->get();
    }

    /**
     * 依 id 查詢 Chunk 與所屬文件（Citation 顯示來源用）。查不到的 id 不會出現在結果中。
     *
     * @param  list<int>  $ids
     * @return Collection<int, DocumentChunk> 以 id 為鍵
     */
    public function findWithDocuments(array $ids): Collection
    {
        return DocumentChunk::with('document')->whereKey($ids)->get()->keyBy('id');
    }

    /**
     * 依 id 查詢「已建立索引」文件的 Chunk（檢索結果的內容與 Metadata 以 MySQL 為準）。
     *
     * @param  list<int>  $ids
     * @return Collection<int, DocumentChunk> 以 id 為鍵
     */
    public function findIndexed(array $ids): Collection
    {
        return DocumentChunk::with('document')->whereKey($ids)
            ->whereHas('document', fn ($q) => $q->where('status_key', DocumentStatus::Indexed))
            ->get()->keyBy('id');
    }

    /**
     * 以新 Chunk 整批取代舊 Chunk。刪除與寫入在同一個 Transaction：重新切段不會產生重複（冪等），
     * 中途失敗也不會留下一半的 Chunk。unique(document_id, chunk_index) 是最後一道防線。
     *
     * @param  list<ChunkDraft>  $drafts
     */
    public function replace(int $documentId, array $drafts, string $strategy): void
    {
        DB::transaction(function () use ($documentId, $drafts, $strategy) {
            DocumentChunk::where('document_id', $documentId)->delete();

            $now = now();
            $rows = array_map(fn (ChunkDraft $draft) => [
                'document_id' => $documentId,
                'chunk_index' => $draft->index,
                'content' => $draft->content,
                'search_text' => $this->searchText->normalize($draft->content),
                'char_count' => $draft->charCount,
                'token_count' => $draft->tokenCount,
                'page_start' => $draft->pageStart,
                'page_end' => $draft->pageEnd,
                'section' => $draft->section,
                'chunk_strategy' => $strategy,
                'created_at' => $now,
            ], $drafts);

            foreach (array_chunk($rows, 100) as $batch) {
                DocumentChunk::insert($batch);
            }
        });
    }
}
