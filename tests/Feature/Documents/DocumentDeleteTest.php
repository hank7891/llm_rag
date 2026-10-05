<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Repositories\DocumentPageRepository;
use App\Repositories\DocumentRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentDeleteTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = 'http://qdrant.test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rag.qdrant.url', self::BASE);
        Storage::fake(config('documents.disk'));
    }

    private function document(DocumentStatus $status = DocumentStatus::Indexed, string $name = '差勤規章.pdf'): Document
    {
        Storage::disk(config('documents.disk'))->put('documents/x.pdf', 'pdf');
        $document = (new DocumentRepository)->create([
            'name' => $name, 'mime_type' => 'application/pdf', 'path' => 'documents/x.pdf', 'size' => 1, 'sha256' => str_repeat('0', 64),
        ]);
        (new DocumentPageRepository)->replace($document, [1 => '第一頁']);
        $document->forceFill(['status_key' => $status])->save();

        return $document;
    }

    private function fakeQdrant(int $deleteStatus = 200): void
    {
        Http::fake([
            self::BASE.'/collections' => Http::response(['result' => ['collections' => [['name' => 'company_docs_bge_m3'], ['name' => 'company_docs_qwen3_embedding_0_6b']]]]),
            self::BASE.'/collections/*/points/delete*' => Http::response($deleteStatus === 200 ? ['result' => ['status' => 'completed']] : ['status' => ['error' => 'timeout']], $deleteStatus),
        ]);
    }

    public function test_delete_purges_qdrant_then_mysql_and_file(): void
    {
        $this->fakeQdrant();
        $document = $this->document();

        $this->delete(route('documents.destroy', $document))->assertRedirect(route('documents.index'))->assertSessionHas('success');

        $this->assertSame(
            [null, 0, false],
            [Document::find($document->id), DocumentPage::where('document_id', $document->id)->count(), Storage::disk(config('documents.disk'))->exists('documents/x.pdf')],
        );
    }

    public function test_delete_clears_every_model_collection(): void
    {
        $this->fakeQdrant();
        $document = $this->document();

        $this->delete(route('documents.destroy', $document));

        $this->assertSame(
            ['/collections/company_docs_bge_m3/points/delete', '/collections/company_docs_qwen3_embedding_0_6b/points/delete'],
            Http::recorded(fn (Request $r) => $r->method() === 'POST')->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all(),
        );
    }

    public function test_qdrant_failure_keeps_mysql_and_file(): void
    {
        $this->fakeQdrant(deleteStatus: 500);
        $document = $this->document();

        $this->from(route('documents.index'))->delete(route('documents.destroy', $document))->assertSessionHas('error');

        $this->assertSame(
            [true, 1, true],
            [Document::whereKey($document->id)->exists(), $document->pages()->count(), Storage::disk(config('documents.disk'))->exists('documents/x.pdf')],
        );
    }

    public function test_document_being_processed_cannot_be_deleted(): void
    {
        $this->fakeQdrant();
        $document = $this->document(DocumentStatus::Indexing);

        $this->from(route('documents.index'))->delete(route('documents.destroy', $document))->assertSessionHas('error');

        $this->assertTrue(Document::whereKey($document->id)->exists());
        Http::assertNothingSent();
    }

    public function test_list_shows_delete_button_only_for_idle_documents(): void
    {
        $this->withoutVite();
        // 刪除與逐頁檢視的網址相同（/document/{id}），以確認對話框的文字判斷有沒有刪除按鈕
        $this->document(DocumentStatus::Indexed, '可刪除.pdf');
        $this->document(DocumentStatus::Indexing, '處理中.pdf');

        $this->get(route('documents.index'))
            ->assertSee('確定要刪除「可刪除.pdf」', false)
            ->assertDontSee('確定要刪除「處理中.pdf」', false);
    }
}
