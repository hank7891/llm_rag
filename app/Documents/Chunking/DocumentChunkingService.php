<?php

namespace App\Documents\Chunking;

use App\Documents\DocumentStatus;
use App\Documents\Exceptions\StaleDocumentStatusException;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Repositories\DocumentChunkRepository;
use App\Repositories\DocumentPageRepository;
use App\Repositories\DocumentRepository;

/**
 * 切段一份文件：parsed / chunked → chunking → 切段 → 整批取代 Chunk → chunked。
 * 由 ChunkDocumentJob（解析完成後自動）或 rag:chunk 指令（實驗時重新切段）呼叫；可重複執行（冪等）。
 */
class DocumentChunkingService
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentPageRepository $pages,
        private readonly DocumentChunkRepository $chunks,
        private readonly ChunkingService $chunking,
    ) {}

    /**
     * @return list<ChunkDraft>|null 切出的 Chunk；文件不存在或狀態不適合切段（例如重複的 Job）時回傳 null
     */
    public function chunk(int $documentId, ?ChunkingOptions $options = null): ?array
    {
        $document = $this->documents->find($documentId);

        if ($document === null || ! $this->start($document)) {
            return null;
        }

        $options ??= $this->chunking->defaults();
        $pages = $this->pages->forDocument($documentId)
            ->mapWithKeys(fn (DocumentPage $page) => [$page->page_number => $page->content])
            ->all();

        $drafts = $this->chunking->chunk($pages, $options);

        // 先寫 Chunk、再改狀態：改狀態前失敗時，重試會整批覆蓋，不會重複
        $this->chunks->replace($documentId, $drafts, $options->label());
        $this->documents->transition($document, DocumentStatus::Chunked);

        return $drafts;
    }

    /** 標記為失敗並記錄原因（由 Job 在重試用盡或逾時時呼叫） */
    public function markFailed(int $documentId, string $reason): void
    {
        $document = $this->documents->find($documentId);

        if ($document === null || ! $document->status_key->canTransitionTo(DocumentStatus::Failed)) {
            return;
        }

        try {
            $this->documents->transition($document, DocumentStatus::Failed, ['error_message' => $reason]);
        } catch (StaleDocumentStatusException) {
            // 讀取後狀態已被其他程序改變：不覆寫對方的結果
        }
    }

    /**
     * parsed / chunked：轉為 chunking 開始切段；chunking：上一次因暫時性錯誤中斷，這是重試，直接繼續；
     * 其他狀態（還沒解析完、已失敗、正在建索引）：不切段。
     */
    private function start(Document $document): bool
    {
        if ($document->status_key === DocumentStatus::Chunking) {
            return true;
        }

        if (! $document->status_key->canTransitionTo(DocumentStatus::Chunking)) {
            return false;
        }

        try {
            $this->documents->transition($document, DocumentStatus::Chunking);
        } catch (StaleDocumentStatusException) {
            return false; // 同一時間另一個程序已經開始切段
        }

        return true;
    }
}
