<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStatus;
use App\Documents\Exceptions\InvalidStatusTransitionException;
use App\Documents\Exceptions\StaleDocumentStatusException;
use App\Models\Document;
use App\Repositories\DocumentPageRepository;
use App\Repositories\DocumentRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private DocumentRepository $documents;

    private DocumentPageRepository $pages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documents = new DocumentRepository;
        $this->pages = new DocumentPageRepository;
    }

    private function document(string $sha256 = 'abc'): Document
    {
        return $this->documents->create([
            'name' => '員工管理辦法.pdf',
            'mime_type' => 'application/pdf',
            'path' => 'documents/test.pdf',
            'size' => 1234,
            'sha256' => str_pad($sha256, 64, '0'),
        ]);
    }

    public function test_new_document_starts_as_uploaded(): void
    {
        $this->assertSame(DocumentStatus::Uploaded, $this->document()->fresh()->status_key);
    }

    public function test_transition_updates_status_and_extra_fields(): void
    {
        $document = $this->document();
        $this->documents->transition($document, DocumentStatus::Parsing);

        $this->documents->transition($document, DocumentStatus::Parsed, ['page_count' => 3]);

        $fresh = $document->fresh();
        $this->assertSame([DocumentStatus::Parsed, 3], [$fresh->status_key, $fresh->page_count]);
    }

    public function test_invalid_transition_is_rejected_and_not_saved(): void
    {
        $document = $this->document();

        try {
            $this->documents->transition($document, DocumentStatus::Parsed);
            $this->fail('Expected InvalidStatusTransitionException.');
        } catch (InvalidStatusTransitionException) {
            $this->assertSame(DocumentStatus::Uploaded, $document->fresh()->status_key);
        }
    }

    public function test_transition_from_stale_model_is_rejected(): void
    {
        // 兩個程序讀到同一份文件；A 先改成 parsing，B 手上的 Model 仍是 uploaded
        $document = $this->document();
        $staleCopy = $this->documents->find($document->id);
        $this->documents->transition($document, DocumentStatus::Parsing);

        $this->expectException(StaleDocumentStatusException::class);

        $this->documents->transition($staleCopy, DocumentStatus::Parsing);
    }

    public function test_stale_transition_does_not_overwrite_newer_status(): void
    {
        $document = $this->document();
        $this->documents->transition($document, DocumentStatus::Parsing);
        $staleCopy = $this->documents->find($document->id);
        $this->documents->transition($document, DocumentStatus::Parsed, ['page_count' => 3]);

        try {
            $this->documents->transition($staleCopy, DocumentStatus::Failed, ['error_message' => '較晚的失敗']);
        } catch (StaleDocumentStatusException) {
        }

        $this->assertSame(DocumentStatus::Parsed, $document->fresh()->status_key);
    }

    public function test_ids_by_sha256_groups_duplicates(): void
    {
        $first = $this->document('dup');
        $second = $this->document('dup');
        $this->document('other');

        $this->assertSame([$first->id, $second->id], $this->documents->idsBySha256([str_pad('dup', 64, '0')])->first());
    }

    // ---- 頁面 ----

    public function test_replace_writes_pages_in_order(): void
    {
        $document = $this->document();

        $this->pages->replace($document, [1 => '第一頁', 2 => '第二頁']);

        $this->assertSame([1 => '第一頁', 2 => '第二頁'], $document->pages()->pluck('content', 'page_number')->all());
    }

    public function test_replace_twice_does_not_duplicate_pages(): void
    {
        $document = $this->document();

        $this->pages->replace($document, [1 => '舊的一', 2 => '舊的二', 3 => '舊的三']);
        $this->pages->replace($document, [1 => '新的一', 2 => '新的二']);

        $this->assertSame([1 => '新的一', 2 => '新的二'], $document->pages()->pluck('content', 'page_number')->all());
    }

    public function test_database_rejects_duplicate_page_numbers(): void
    {
        $document = $this->document();
        $this->pages->replace($document, [1 => '第一頁']);

        $this->expectException(QueryException::class);

        DB::table('document_pages')->insert(['document_id' => $document->id, 'page_number' => 1, 'content' => '重複']);
    }

    public function test_long_page_content_fits_mediumtext(): void
    {
        $document = $this->document();
        $content = str_repeat('中', 30000); // 90KB，超過 TEXT 的 64KB 上限

        $this->pages->replace($document, [1 => $content]);

        $this->assertSame($content, $document->pages()->first()->content);
    }
}
