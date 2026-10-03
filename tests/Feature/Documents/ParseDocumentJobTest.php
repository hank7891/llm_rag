<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStatus;
use App\Jobs\ChunkDocumentJob;
use App\Jobs\ParseDocumentJob;
use App\Models\Document;
use App\Repositories\DocumentPageRepository;
use App\Repositories\DocumentRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ParseDocumentJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('documents.disk'));
        // 解析完成後會派送 ChunkDocumentJob；測試環境的 Queue 是 sync，不攔下來會立刻接著切段
        Queue::fake();
    }

    /** 把樣本檔放進假的 Disk 並建立 uploaded 狀態的文件 */
    private function upload(string $fixture, string $mimeType, ?string $name = null): Document
    {
        $path = 'documents/'.$fixture;
        Storage::disk(config('documents.disk'))->put($path, file_get_contents(base_path("tests/fixtures/documents/{$fixture}")));

        return (new DocumentRepository)->create([
            'name' => $name ?? $fixture,
            'mime_type' => $mimeType,
            'path' => $path,
            'size' => 1,
            'sha256' => str_repeat('0', 64),
        ]);
    }

    private function handleJob(Document $document): ParseDocumentJob
    {
        // withFakeQueueInteractions：不經過真的 Queue，但能檢查 Job 是否呼叫了 fail() / release()
        $job = (new ParseDocumentJob($document->id))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        return $job;
    }

    public function test_pdf_is_parsed_page_by_page(): void
    {
        $document = $this->upload('handbook.pdf', 'application/pdf');

        $this->handleJob($document);

        $fresh = $document->fresh();
        $this->assertSame(
            [DocumentStatus::Parsed, 3, [1, 2, 3], null],
            [$fresh->status_key, $fresh->page_count, $fresh->pages()->pluck('page_number')->all(), $fresh->error_message],
        );
    }

    public function test_chunk_job_is_dispatched_after_parsing(): void
    {
        $document = $this->upload('handbook.pdf', 'application/pdf');

        $this->handleJob($document);

        Queue::assertPushed(ChunkDocumentJob::class, fn (ChunkDocumentJob $job) => $job->documentId === $document->id);
    }

    public function test_chunk_job_is_not_dispatched_when_parsing_fails(): void
    {
        $this->handleJob($this->upload('broken.pdf', 'application/pdf'));

        Queue::assertNotPushed(ChunkDocumentJob::class);
    }

    public function test_parsed_pages_are_normalized(): void
    {
        $document = $this->upload('handbook.pdf', 'application/pdf');

        $this->handleJob($document);

        $page2 = $document->pages()->where('page_number', 2)->value('content');
        $this->assertSame(
            [false, false, true],
            [str_contains($page2, '公司機密'), str_contains($page2, '第2頁'), str_contains($page2, 'HR-2026')],
        );
    }

    public function test_markdown_is_parsed_as_single_page(): void
    {
        $document = $this->upload('guide.md', 'text/plain');

        $this->handleJob($document);

        $this->assertStringStartsWith('# 新進員工指南', $document->pages()->value('content'));
    }

    public function test_big5_text_is_stored_as_utf8(): void
    {
        $document = $this->upload('notice-big5.txt', 'text/plain');

        $this->handleJob($document);

        $this->assertStringContainsString('事假全年合計不得超過十四日', $document->pages()->value('content'));
    }

    // ---- 冪等 ----

    public function test_retry_while_parsing_does_not_duplicate_pages(): void
    {
        $document = $this->upload('handbook.pdf', 'application/pdf');
        (new DocumentRepository)->transition($document, DocumentStatus::Parsing);
        (new DocumentPageRepository)->replace($document, [1 => '上一次執行留下的舊頁面']);

        $this->handleJob($document);

        $this->assertSame([1, 2, 3], $document->pages()->pluck('page_number')->all());
    }

    public function test_duplicate_job_for_parsed_document_does_nothing(): void
    {
        $document = $this->upload('handbook.pdf', 'application/pdf');
        $this->handleJob($document);
        $updatedAt = $document->fresh()->updated_at;

        $this->travel(5)->seconds();
        $this->handleJob($document);

        $this->assertEquals($updatedAt, $document->fresh()->updated_at);
    }

    public function test_missing_document_is_ignored(): void
    {
        $job = (new ParseDocumentJob(999999))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $job->assertNotFailed();
    }

    // ---- 永久性錯誤：不重試，直接失敗 ----

    public function test_broken_pdf_fails_immediately_without_retry(): void
    {
        $document = $this->upload('broken.pdf', 'application/pdf');

        // assertFailed：Job 呼叫了 fail()，真的 Queue 會直接移到 failed_jobs，不再重試
        $this->handleJob($document)->assertFailed();
    }

    public function test_failed_callback_marks_document_failed(): void
    {
        $document = $this->upload('broken.pdf', 'application/pdf');

        // 假的 Queue 只記錄 fail()，不會像真的 Worker 接著呼叫 failed()，所以這裡手動呼叫
        $job = $this->handleJob($document);
        $job->failed($job->job->failedWith);

        $this->assertSame(DocumentStatus::Failed, $document->fresh()->status_key);
    }

    public function test_failure_reason_is_readable(): void
    {
        $document = $this->upload('broken.pdf', 'application/pdf');

        $job = $this->handleJob($document);
        $job->failed($job->job->failedWith);

        $this->assertStringStartsWith('PDF 解析失敗', $document->fresh()->error_message);
    }

    public function test_scanned_pdf_fails_as_suspected_scan(): void
    {
        $document = $this->upload('scanned.pdf', 'application/pdf');

        $job = $this->handleJob($document);
        $job->failed($job->job->failedWith);

        $this->assertStringStartsWith('疑似掃描檔', $document->fresh()->error_message);
    }

    // ---- 暫時性錯誤：丟回給 Queue 重試 ----

    public function test_transient_error_is_rethrown_for_retry(): void
    {
        $document = $this->upload('handbook.pdf', 'application/pdf');
        $this->mock(DocumentPageRepository::class)->shouldReceive('replace')->andThrow(new RuntimeException('Deadlock found'));

        try {
            $this->handleJob($document);
            $this->fail('Expected RuntimeException to reach the queue.');
        } catch (RuntimeException $e) {
            $this->assertSame([DocumentStatus::Parsing, 'Deadlock found'], [$document->fresh()->status_key, $e->getMessage()]);
        }
    }

    public function test_exhausted_retries_mark_document_failed(): void
    {
        $document = $this->upload('handbook.pdf', 'application/pdf');
        (new DocumentRepository)->transition($document, DocumentStatus::Parsing);

        (new ParseDocumentJob($document->id))->failed(new RuntimeException('Deadlock found'));

        $fresh = $document->fresh();
        $this->assertSame(
            [DocumentStatus::Failed, '處理時發生系統錯誤或逾時（RuntimeException）。可稍後重新處理。'],
            [$fresh->status_key, $fresh->error_message],
        );
    }

    public function test_late_failed_callback_does_not_overwrite_parsed_document(): void
    {
        // 例如重複的 Job 失敗時，另一個 Job 已經把文件解析完成
        $document = $this->upload('handbook.pdf', 'application/pdf');
        $this->handleJob($document);

        (new ParseDocumentJob($document->id))->failed(new RuntimeException('late failure'));

        $this->assertSame([DocumentStatus::Parsed, null], [$document->fresh()->status_key, $document->fresh()->error_message]);
    }

    public function test_job_fails_on_timeout_instead_of_retrying(): void
    {
        $this->assertTrue((new ParseDocumentJob(1))->failOnTimeout);
    }

    public function test_job_timeout_is_shorter_than_queue_retry_after(): void
    {
        $this->assertLessThan(config('queue.connections.database.retry_after'), (new ParseDocumentJob(1))->timeout);
    }
}
