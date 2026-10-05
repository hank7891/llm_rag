<?php

namespace App\Rag;

use App\Ai\Embedding\EmbeddingModels;
use App\Ai\Embedding\EmbeddingService;
use App\Documents\DocumentStatus;
use App\Documents\Exceptions\StaleDocumentStatusException;
use App\Models\Document;
use App\Rag\VectorStore\ChunkIndexer;
use App\Repositories\DocumentRepository;

/**
 * 建立一份文件的向量索引，並管理文件狀態。
 *
 * documents.status 只代表「目前線上使用的模型」的 Collection：
 * - 目前的模型：chunked / indexed → indexing → 寫入 → indexed
 * - 其他模型（rag:index --model=，Ch08 比較用）：只寫入該模型的 Collection，不改狀態
 */
class DocumentIndexingService
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly ChunkIndexer $indexer,
        private readonly EmbeddingService $embedding,
    ) {}

    /**
     * @return int|null 寫入的 Point 數；文件不存在或狀態不適合建索引時回傳 null
     */
    public function index(int $documentId, ?string $model = null): ?int
    {
        $document = $this->documents->find($documentId);

        if ($document === null) {
            return null;
        }

        if (! $this->isCurrentModel($model)) {
            return $document->status_key->hasPages() ? $this->indexer->index($document, $model) : null;
        }

        if (! $this->start($document)) {
            return null;
        }

        $count = $this->indexer->index($document);
        $this->documents->transition($document, DocumentStatus::Indexed);

        return $count;
    }

    public function isCurrentModel(?string $model): bool
    {
        return $model === null || EmbeddingModels::canonical($model) === $this->embedding->defaultModel();
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

    /** chunked / indexed → indexing；indexing 代表上一次中斷後的重試，直接繼續；其他狀態不建索引 */
    private function start(Document $document): bool
    {
        if ($document->status_key === DocumentStatus::Indexing) {
            return true;
        }

        if (! $document->status_key->canTransitionTo(DocumentStatus::Indexing)) {
            return false;
        }

        try {
            $this->documents->transition($document, DocumentStatus::Indexing);
        } catch (StaleDocumentStatusException) {
            return false;
        }

        return true;
    }
}
