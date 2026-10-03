<?php

namespace App\Repositories;

use App\Documents\Chunking\ChunkDraft;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DocumentChunkRepository
{
    /** @return Collection<int, DocumentChunk> 依 chunk_index 排序 */
    public function forDocument(int $documentId): Collection
    {
        return DocumentChunk::where('document_id', $documentId)->orderBy('chunk_index')->get();
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
