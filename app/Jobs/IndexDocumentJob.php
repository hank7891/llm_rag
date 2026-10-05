<?php

namespace App\Jobs;

use App\Rag\DocumentIndexingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 背景建立向量索引（切段完成後自動派送），只索引到目前設定的模型。只傳 document_id。
 * 失敗時只需重跑這一步，不必重新切段（Ch07 決策：不併入 ChunkDocumentJob）。
 */
class IndexDocumentJob implements ShouldQueue
{
    use Queueable;

    /** Embedding 模型冷啟動、Qdrant 暫時無法連線等暫時性錯誤，最多嘗試 3 次 */
    public int $tries = 3;

    public array $backoff = [10, 30];

    /** 必須小於 config/queue.php 的 retry_after（90 秒） */
    public int $timeout = 80;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $documentId) {}

    public function handle(DocumentIndexingService $service): void
    {
        $service->index($this->documentId);
    }

    public function failed(?Throwable $e): void
    {
        app(DocumentIndexingService::class)->markFailed(
            $this->documentId,
            sprintf('建立索引時發生錯誤（%s）：%s', $e === null ? 'Unknown' : class_basename($e), $e === null ? '' : mb_substr($e->getMessage(), 0, 200)),
        );
    }
}
