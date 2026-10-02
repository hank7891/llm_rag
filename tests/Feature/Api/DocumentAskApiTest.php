<?php

namespace Tests\Feature\Api;

use App\Documents\DocumentStatus;
use App\Models\Document;
use App\Repositories\DocumentPageRepository;
use App\Repositories\DocumentRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DocumentAskApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('llm.ollama.base_url', 'http://ollama.test');
        config()->set('llm.openai.base_url', 'https://openai.test/v1');
        config()->set('llm.openai.api_key', 'sk-test');
    }

    private function document(int $chars = 100, DocumentStatus $status = DocumentStatus::Parsed): Document
    {
        $document = (new DocumentRepository)->create([
            'name' => '工作規則.pdf', 'mime_type' => 'application/pdf', 'path' => 'documents/x.pdf', 'size' => 1, 'sha256' => str_repeat('0', 64),
        ]);
        (new DocumentPageRepository)->replace($document, [1 => str_repeat('規', $chars)]);
        $document->forceFill(['status_key' => $status])->save();

        return $document;
    }

    private function fakeOllama(int $promptEvalCount = 90): void
    {
        Http::fake(['ollama.test/api/chat' => Http::response([
            'model' => 'qwen3:8b', 'message' => ['content' => '資料不足'], 'done' => true, 'done_reason' => 'stop',
            'prompt_eval_count' => $promptEvalCount, 'eval_count' => 3,
        ])]);
    }

    private function ask(Document $document, array $payload = []): TestResponse
    {
        return $this->postJson("/api/documents/{$document->id}/ask", ['question' => '特休幾天？', 'provider' => 'ollama', ...$payload]);
    }

    public function test_response_contains_answer_usage_and_meta(): void
    {
        $this->fakeOllama();

        $this->ask($this->document())
            ->assertOk()
            ->assertExactJsonStructure([
                'answer', 'usage' => ['input_tokens', 'output_tokens'], 'model', 'finish_reason',
                'meta' => ['prompt_eval_count', 'document_pages', 'document_chars', 'estimated_tokens', 'truncated_suspected', 'duration_ms'],
            ])
            ->assertJsonPath('answer', '資料不足');
    }

    public function test_num_ctx_is_sent_to_ollama(): void
    {
        $this->fakeOllama();

        $this->ask($this->document(), ['num_ctx' => 4096]);

        Http::assertSent(fn (Request $request) => $request['options']['num_ctx'] === 4096);
    }

    public function test_truncation_is_reported_when_long_document_exceeds_num_ctx(): void
    {
        // 16000 字粗估約 12000 Token，num_ctx 4096 時 Ollama 只處理約一半
        $this->fakeOllama(promptEvalCount: 2050);

        $this->ask($this->document(chars: 16000), ['num_ctx' => 4096])->assertJsonPath('meta.truncated_suspected', true);
    }

    public function test_switching_provider_uses_same_endpoint_and_response_shape(): void
    {
        $this->fakeOllama();
        Http::fake(['openai.test/*' => Http::response([
            'status' => 'completed', 'model' => 'gpt-4.1-mini',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '資料不足']]]],
            'usage' => ['input_tokens' => 80, 'output_tokens' => 3],
        ])]);

        $document = $this->document();

        $this->ask($document, ['provider' => 'openai'])
            ->assertOk()
            ->assertJsonPath('answer', '資料不足')
            // 雲端超過上限會直接回錯誤，不會靜默截斷，因此無法判斷
            ->assertJsonPath('meta.truncated_suspected', null);
    }

    public function test_document_not_parsed_returns_409(): void
    {
        $this->ask($this->document(status: DocumentStatus::Parsing))
            ->assertStatus(409)
            ->assertJsonPath('error.type', 'document_not_ready');
    }

    public function test_missing_document_returns_404(): void
    {
        $this->postJson('/api/documents/999999/ask', ['question' => '特休幾天？'])->assertNotFound();
    }

    public function test_question_is_required(): void
    {
        $this->postJson("/api/documents/{$this->document()->id}/ask", [])->assertStatus(422)->assertJsonValidationErrors('question');
    }

    public function test_num_ctx_must_be_reasonable(): void
    {
        $this->ask($this->document(), ['num_ctx' => 100])->assertStatus(422)->assertJsonValidationErrors('num_ctx');
    }
}
