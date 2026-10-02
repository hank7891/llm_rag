<?php

namespace App\Repositories;

use App\Models\Document;
use App\Models\DocumentPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DocumentPageRepository
{
    /** @return Collection<int, DocumentPage> 依頁碼排序 */
    public function forDocument(int $documentId): Collection
    {
        return DocumentPage::where('document_id', $documentId)->orderBy('page_number')->get();
    }

    /**
     * 以新頁面整批取代舊頁面。刪除與寫入在同一個 Transaction：Job 重跑不會產生重複頁面（冪等），
     * 中途失敗也不會留下「舊頁面已刪、新頁面只寫一半」的狀態。
     *
     * @param  array<int, string>  $pages  頁碼 → 內容
     */
    public function replace(Document $document, array $pages): void
    {
        DB::transaction(function () use ($document, $pages) {
            DocumentPage::where('document_id', $document->id)->delete();

            $now = now();
            $rows = [];

            foreach ($pages as $pageNumber => $content) {
                $rows[] = [
                    'document_id' => $document->id,
                    'page_number' => $pageNumber,
                    'content' => $content,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // 分批寫入：單頁內容可能很長，避免單一 SQL 過大
            foreach (array_chunk($rows, 100) as $chunk) {
                DocumentPage::insert($chunk);
            }
        });
    }
}
