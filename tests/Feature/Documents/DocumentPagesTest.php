<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStatus;
use App\Jobs\IndexDocumentJob;
use App\Jobs\ParseDocumentJob;
use App\Models\Document;
use App\Repositories\DocumentPageRepository;
use App\Repositories\DocumentRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentPagesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Queue::fake();
        Storage::fake(config('documents.disk'));
    }

    /** 以真實樣本檔建立上傳檔（finfo 會讀實際內容判斷 MIME） */
    private function fixture(string $name, ?string $uploadAs = null): UploadedFile
    {
        return new UploadedFile(base_path("tests/fixtures/documents/{$name}"), $uploadAs ?? $name, test: true);
    }

    private function document(DocumentStatus $status = DocumentStatus::Uploaded, string $sha = 'a'): Document
    {
        $document = (new DocumentRepository)->create([
            'name' => '員工管理辦法.pdf', 'mime_type' => 'application/pdf', 'path' => 'documents/x.pdf', 'size' => 2048, 'sha256' => str_pad($sha, 64, '0'),
        ]);
        $document->forceFill(['status_key' => $status])->save();

        return $document;
    }

    // ---- 上傳 ----

    public function test_upload_stores_file_creates_document_and_dispatches_job(): void
    {
        $this->post(route('documents.store'), ['file' => $this->fixture('handbook.pdf')])
            ->assertRedirect(route('documents.index'));

        $document = Document::latest('id')->first();
        Storage::disk(config('documents.disk'))->assertExists($document->path);
        Queue::assertPushed(ParseDocumentJob::class, fn (ParseDocumentJob $job) => $job->documentId === $document->id);
    }

    public function test_upload_records_detected_metadata(): void
    {
        $this->post(route('documents.store'), ['file' => $this->fixture('handbook.pdf')]);

        $document = Document::latest('id')->first();
        $this->assertSame(
            ['handbook.pdf', 'application/pdf', filesize(base_path('tests/fixtures/documents/handbook.pdf')), hash_file('sha256', base_path('tests/fixtures/documents/handbook.pdf')), DocumentStatus::Uploaded],
            [$document->name, $document->mime_type, $document->size, $document->sha256, $document->status_key],
        );
    }

    public function test_upload_does_not_use_original_name_as_storage_path(): void
    {
        $this->post(route('documents.store'), ['file' => $this->fixture('handbook.pdf', '../../evil name.pdf')]);

        $this->assertStringNotContainsString('evil', Document::latest('id')->first()->path);
    }

    /** @return array<string, array{string, string}> */
    public static function acceptedFiles(): array
    {
        return [
            'pdf' => ['handbook.pdf', 'handbook.pdf'],
            'txt' => ['notice-utf8.txt', 'notice.txt'],
            'big5 txt' => ['notice-big5.txt', 'notice.txt'],
            'markdown' => ['guide.md', 'guide.md'],
            '內容是 PDF 但已損毀（交給背景解析判斷）' => ['corrupted.pdf', 'corrupted.pdf'],
        ];
    }

    #[DataProvider('acceptedFiles')]
    public function test_supported_files_are_accepted(string $fixture, string $uploadAs): void
    {
        $this->post(route('documents.store'), ['file' => $this->fixture($fixture, $uploadAs)])->assertSessionHasNoErrors();
    }

    /** @return array<string, array{string, string, string}> */
    public static function rejectedFiles(): array
    {
        return [
            '偽裝成 PDF 的純文字' => ['broken.pdf', 'broken.pdf', '檔案內容與副檔名不符'],
            '把 PDF 改成 txt' => ['handbook.pdf', 'handbook.txt', '檔案內容與副檔名不符'],
            '不支援的副檔名' => ['notice-utf8.txt', 'notice.docx', '只支援 PDF、TXT、Markdown'],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_invalid_files_are_rejected_with_readable_message(string $fixture, string $uploadAs, string $message): void
    {
        // assertInvalid 以「包含」比對錯誤訊息
        $this->post(route('documents.store'), ['file' => $this->fixture($fixture, $uploadAs)])->assertInvalid(['file' => $message]);

        Queue::assertNothingPushed();
    }

    public function test_unsupported_extension_reports_only_one_error(): void
    {
        $response = $this->post(route('documents.store'), ['file' => $this->fixture('notice-utf8.txt', 'notice.docx')]);

        // Laravel 13 的 session 以陣列保存錯誤：{default: {messages: {file: [...]}}}
        $this->assertSame(
            ['只支援 PDF、TXT、Markdown 檔案。'],
            data_get($response->baseResponse->getSession()->get('errors'), 'default.messages.file'),
        );
    }

    public function test_file_over_size_limit_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('big.pdf', config('documents.max_upload_kb') + 1, 'application/pdf');

        $this->post(route('documents.store'), ['file' => $file])->assertInvalid(['file' => '檔案不可超過 8192 KB']);
    }

    public function test_missing_file_is_rejected(): void
    {
        $this->post(route('documents.store'))->assertInvalid(['file' => '請選擇要上傳的檔案']);
    }

    // ---- 列表 ----

    public function test_index_shows_status_label_and_error(): void
    {
        $document = $this->document(DocumentStatus::Failed);
        $document->forceFill(['error_message' => '疑似掃描檔：平均每頁只抽出 0 個字'])->save();

        $this->get(route('documents.index'))->assertOk()->assertSeeInOrder(['員工管理辦法.pdf', '疑似掃描檔', '處理失敗']);
    }

    public function test_index_marks_duplicate_uploads(): void
    {
        $first = $this->document(sha: 'same');
        $second = $this->document(sha: 'same');

        $this->get(route('documents.index'))->assertSee("與 #{$first->id} 內容相同")->assertSee("與 #{$second->id} 內容相同");
    }

    /** @return array<string, array{DocumentStatus, string}> */
    public static function pollingStatuses(): array
    {
        return [
            '解析中要輪詢' => [DocumentStatus::Parsing, '1'],
            'Job 之間的 parsed 也要輪詢' => [DocumentStatus::Parsed, '1'],
            '索引完成不輪詢' => [DocumentStatus::Indexed, '0'],
            '失敗不輪詢' => [DocumentStatus::Failed, '0'],
        ];
    }

    #[DataProvider('pollingStatuses')]
    public function test_index_marks_rows_to_poll_only_while_processing(DocumentStatus $status, string $processing): void
    {
        $document = $this->document($status);

        $this->get(route('documents.index'))->assertSee("data-document-id=\"{$document->id}\" data-status=\"{$status->value}\" data-processing=\"{$processing}\"", false);
    }

    public function test_statuses_endpoint_returns_only_requested_documents(): void
    {
        $parsing = $this->document(DocumentStatus::Parsing);
        $this->document(DocumentStatus::Indexed, 'b');

        $this->getJson(route('documents.statuses', ['ids' => [$parsing->id]]))->assertExactJson([(string) $parsing->id => 'parsing']);
    }

    public function test_delete_confirmation_does_not_put_file_name_into_javascript(): void
    {
        $document = $this->document(DocumentStatus::Indexed);
        $document->forceFill(['name' => "a');alert(1);//.pdf"])->save();

        $this->get(route('documents.index'))
            ->assertDontSee('onsubmit', false)
            ->assertSee('data-confirm="確定要刪除「a&#039;);alert(1);//.pdf」', false);
    }

    public function test_indexed_document_can_be_reindexed(): void
    {
        $document = $this->document(DocumentStatus::Indexed);

        $this->post(route('documents.reindex', $document))->assertRedirect();

        Queue::assertPushed(IndexDocumentJob::class, fn (IndexDocumentJob $job) => $job->documentId === $document->id);
    }

    public function test_processing_document_cannot_be_reindexed(): void
    {
        $document = $this->document(DocumentStatus::Parsing);

        $this->post(route('documents.reindex', $document))->assertSessionHas('error');

        Queue::assertNotPushed(IndexDocumentJob::class);
    }

    public function test_upload_of_duplicate_content_names_existing_document(): void
    {
        $this->post(route('documents.store'), ['file' => $this->fixture('notice-utf8.txt')]);

        $this->post(route('documents.store'), ['file' => $this->fixture('notice-utf8.txt', '另一個檔名.txt')])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '內容與既有的「notice-utf8.txt」相同'));
    }

    // ---- 逐頁檢視 ----

    public function test_show_lists_every_page_in_order(): void
    {
        $document = $this->document(DocumentStatus::Parsed);
        (new DocumentPageRepository)->replace($document, [1 => '第一條 目的', 2 => '', 3 => '第二十條 附則']);

        $this->get(route('documents.show', $document))
            ->assertOk()
            ->assertSeeInOrder(['第 1 頁', '第一條 目的', '第 2 頁', '（空白頁）', '第 3 頁', '第二十條 附則']);
    }

    // ---- 重新處理 ----

    public function test_failed_document_can_be_reprocessed(): void
    {
        $document = $this->document(DocumentStatus::Failed);

        $this->from(route('documents.index'))->post(route('documents.reprocess', $document))->assertSessionHas('success');

        $this->assertSame(DocumentStatus::Uploaded, $document->fresh()->status_key);
        Queue::assertPushed(ParseDocumentJob::class);
    }

    public function test_document_being_processed_cannot_be_reprocessed(): void
    {
        $document = $this->document(DocumentStatus::Parsing);

        $this->from(route('documents.index'))->post(route('documents.reprocess', $document))->assertSessionHas('error');

        $this->assertSame(DocumentStatus::Parsing, $document->fresh()->status_key);
        Queue::assertNothingPushed();
    }
}
