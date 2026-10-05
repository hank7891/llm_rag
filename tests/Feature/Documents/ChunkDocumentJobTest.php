<?php

namespace Tests\Feature\Documents;

use App\Documents\Chunking\ChunkingService;
use App\Documents\Chunking\DocumentChunkingService;
use App\Documents\DocumentStatus;
use App\Jobs\ChunkDocumentJob;
use App\Jobs\IndexDocumentJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Repositories\DocumentChunkRepository;
use App\Repositories\DocumentPageRepository;
use App\Repositories\DocumentRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ChunkDocumentJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // 切段完成後會派送 IndexDocumentJob；測試環境的 Queue 是 sync，不攔下來會立刻接著呼叫 Qdrant
        Queue::fake();
    }

    /** 第 1 頁兩條規定，第六條跨到第 2 頁 */
    private function document(DocumentStatus $status = DocumentStatus::Parsed): Document
    {
        $document = (new DocumentRepository)->create([
            'name' => '差勤規章.pdf', 'mime_type' => 'application/pdf', 'path' => 'documents/x.pdf', 'size' => 1, 'sha256' => str_repeat('0', 64),
        ]);
        (new DocumentPageRepository)->replace($document, [
            1 => "第一章 總則\n第一條 目的\n為建立差勤制度，特訂定本規章。\n第六條 資料保存\n紀錄應保存至少五年。",
            2 => "如有外洩，應立即通報。\n第七條 施行\n本規章自公布日施行。",
        ]);
        $document->forceFill(['status_key' => $status])->save();

        return $document;
    }

    private function handle(Document $document): void
    {
        $this->app->call([new ChunkDocumentJob($document->id), 'handle']);
    }

    public function test_parsed_document_is_chunked(): void
    {
        $document = $this->document();

        $this->handle($document);

        $this->assertSame(
            [DocumentStatus::Chunked, ['第一章 總則 / 第一條 目的', '第一章 總則 / 第六條 資料保存', '第一章 總則 / 第七條 施行']],
            [$document->fresh()->status_key, $document->chunks()->pluck('section')->all()],
        );
    }

    public function test_index_job_is_dispatched_after_chunking(): void
    {
        $document = $this->document();

        $this->handle($document);

        Queue::assertPushed(IndexDocumentJob::class, fn (IndexDocumentJob $job) => $job->documentId === $document->id);
    }

    public function test_indexed_document_can_be_rechunked(): void
    {
        $document = $this->document(DocumentStatus::Indexed);

        app(DocumentChunkingService::class)->chunk($document->id);

        $this->assertSame(DocumentStatus::Chunked, $document->fresh()->status_key);
    }

    public function test_cross_page_article_records_page_range(): void
    {
        $document = $this->document();

        $this->handle($document);

        $chunk = $document->chunks()->where('section', '第一章 總則 / 第六條 資料保存')->first();
        $this->assertSame([1, 2], [$chunk->page_start, $chunk->page_end]);
    }

    public function test_chunk_strategy_records_parameters(): void
    {
        $document = $this->document();

        $this->handle($document);

        $this->assertSame('structure_v2:max600:ov15', $document->chunks()->value('chunk_strategy'));
    }

    public function test_rechunking_does_not_increase_chunk_count(): void
    {
        $document = $this->document();
        $this->handle($document);
        $first = $document->chunks()->count();

        app(DocumentChunkingService::class)->chunk($document->id);
        app(DocumentChunkingService::class)->chunk($document->id);

        $this->assertSame([$first, range(0, $first - 1)], [$document->chunks()->count(), $document->chunks()->pluck('chunk_index')->all()]);
    }

    public function test_changing_parameters_changes_result_without_code_change(): void
    {
        $document = $this->document();
        $this->handle($document);

        app(DocumentChunkingService::class)->chunk($document->id, app(ChunkingService::class)->defaults()->with(strategy: 'fixed'));

        $this->assertSame([null], $document->chunks()->pluck('section')->unique()->values()->all());
    }

    public function test_retry_while_chunking_continues(): void
    {
        $document = $this->document(DocumentStatus::Chunking);

        $this->handle($document);

        $this->assertSame(DocumentStatus::Chunked, $document->fresh()->status_key);
    }

    public function test_document_not_parsed_is_not_chunked(): void
    {
        $document = $this->document(DocumentStatus::Uploaded);

        $this->handle($document);

        $this->assertSame([DocumentStatus::Uploaded, 0], [$document->fresh()->status_key, $document->chunks()->count()]);
    }

    public function test_failure_rolls_back_and_keeps_old_chunks(): void
    {
        $document = $this->document();
        $this->handle($document);
        $before = $document->chunks()->pluck('content')->all();
        $this->mock(DocumentChunkRepository::class)->shouldReceive('replace')->andThrow(new RuntimeException('Deadlock found'));

        try {
            app(DocumentChunkingService::class)->chunk($document->id);
        } catch (RuntimeException) {
        }

        $this->assertSame($before, DocumentChunk::where('document_id', $document->id)->orderBy('chunk_index')->pluck('content')->all());
    }

    public function test_exhausted_retries_mark_document_failed(): void
    {
        $document = $this->document(DocumentStatus::Chunking);

        (new ChunkDocumentJob($document->id))->failed(new RuntimeException('Deadlock found'));

        $this->assertSame(
            [DocumentStatus::Failed, '切段時發生系統錯誤或逾時（RuntimeException）。可稍後重新處理。'],
            [$document->fresh()->status_key, $document->fresh()->error_message],
        );
    }

    public function test_late_failed_callback_does_not_overwrite_chunked_document(): void
    {
        $document = $this->document();
        $this->handle($document);

        (new ChunkDocumentJob($document->id))->failed(new RuntimeException('late'));

        $this->assertSame(DocumentStatus::Chunked, $document->fresh()->status_key);
    }

    public function test_job_timeout_is_shorter_than_queue_retry_after(): void
    {
        $job = new ChunkDocumentJob(1);

        $this->assertSame([true, true], [$job->timeout < config('queue.connections.database.retry_after'), $job->failOnTimeout]);
    }

    // ---- 指令 ----

    public function test_chunk_command_rechunks_with_options(): void
    {
        $document = $this->document(DocumentStatus::Chunked);

        $this->artisan('rag:chunk', ['document' => $document->id, '--strategy' => 'fixed', '--max-tokens' => 30, '--overlap' => 0.1])
            ->expectsOutputToContain('fixed_v1:max30:ov10')
            ->assertSuccessful();
    }

    public function test_chunk_command_rejects_document_not_ready(): void
    {
        $this->artisan('rag:chunk', ['document' => $this->document(DocumentStatus::Parsing)->id])->assertFailed();
    }

    public function test_chunks_command_lists_chunks_and_statistics(): void
    {
        $document = $this->document();
        $this->handle($document);

        $this->artisan('rag:chunks', ['document' => $document->id])
            ->expectsOutputToContain('第一章 總則 / 第六條 資料保存')
            ->expectsOutputToContain('structure_v2:max600:ov15')
            ->assertSuccessful();
    }

    // ---- 網頁 ----

    public function test_document_page_shows_chunks_tab(): void
    {
        $this->withoutVite();
        $document = $this->document();
        $this->handle($document);

        $this->get(route('documents.show', [$document, 'tab' => 'chunks']))
            ->assertOk()
            ->assertSeeInOrder(['切段結果（3 段）', '第一章 總則 / 第一條 目的', '第一章 總則 / 第六條 資料保存', '第 1–2 頁']);
    }
}
