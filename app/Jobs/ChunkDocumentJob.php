<?php

namespace App\Jobs;

use App\Documents\Chunking\DocumentChunkingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 背景切段文件（解析完成後自動派送）。只傳 document_id，在 Job 內重新查詢。
 * 切段不呼叫外部程式，沒有「壞檔」這類永久性錯誤；任何例外都視為暫時性，重試用盡才標記失敗。
 */
class ChunkDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30];

    /** 必須小於 config/queue.php 的 retry_after（90 秒） */
    public int $timeout = 80;

    /** 逾時直接標記失敗：否則 Worker 逾時會結束 process 且不呼叫 failed()，文件會停在 chunking */
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $documentId) {}

    public function handle(DocumentChunkingService $service): void
    {
        $service->chunk($this->documentId);
    }

    public function failed(?Throwable $e): void
    {
        app(DocumentChunkingService::class)->markFailed(
            $this->documentId,
            sprintf('切段時發生系統錯誤或逾時（%s）。可稍後重新處理。', $e === null ? 'Unknown' : class_basename($e)),
        );
    }
}
