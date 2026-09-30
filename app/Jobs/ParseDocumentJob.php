<?php

namespace App\Jobs;

use App\Documents\DocumentParsingService;
use App\Documents\Parsing\Exceptions\DocumentParseException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 背景解析文件。只傳 document_id：Job 會被序列化存進 jobs 資料表，
 * 傳整個 Model 會存到過期的資料，執行時再查詢才能拿到最新狀態。
 */
class ParseDocumentJob implements ShouldQueue
{
    use Queueable;

    /** 暫時性錯誤（如資料庫中斷）最多嘗試 3 次 */
    public int $tries = 3;

    /** 重試間隔（秒）：第 2 次等 10 秒、第 3 次等 30 秒 */
    public array $backoff = [10, 30];

    /**
     * 單次執行上限（秒），必須小於 config/queue.php 的 retry_after（90 秒）：
     * 否則 Job 還在跑，Queue 就以為它掛了，又派另一個 Worker 重跑同一個 Job。
     */
    public int $timeout = 80;

    /**
     * 逾時直接標記失敗，不再重試：解析逾時多半是檔案太大，重試結果相同。
     * 未設定時，Worker 逾時會直接結束 process 且不呼叫 failed()，文件會停在 parsing。
     */
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $documentId) {}

    public function handle(DocumentParsingService $service): void
    {
        try {
            $service->parse($this->documentId);
        } catch (DocumentParseException $e) {
            // 永久性錯誤（壞檔、疑似掃描檔）：重試也不會成功，直接標記失敗，不佔用後續重試
            $this->fail($e);
        }
    }

    /** 永久性錯誤，或暫時性錯誤重試用盡時呼叫 */
    public function failed(?Throwable $e): void
    {
        $reason = $e instanceof DocumentParseException
            ? $e->getMessage()
            : sprintf('處理時發生系統錯誤或逾時（%s）。可稍後重新處理。', $e === null ? 'Unknown' : class_basename($e));

        app(DocumentParsingService::class)->markFailed($this->documentId, $reason);
    }
}
