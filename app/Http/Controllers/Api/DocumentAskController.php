<?php

namespace App\Http\Controllers\Api;

use App\Documents\Qa\DocumentQaService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskDocumentRequest;
use App\Models\Document;
use Illuminate\Http\JsonResponse;

/**
 * 對單一文件提問（不使用 RAG，整份文件交給 LLM）。不因 Provider 分支。
 */
class DocumentAskController extends Controller
{
    public function __invoke(Document $document, AskDocumentRequest $request, DocumentQaService $qa): JsonResponse
    {
        $answer = $qa->ask($document, $request->validated('question'), $request->chatOptions(), $request->providerName());
        $result = $answer->result;

        return new JsonResponse([
            'answer' => $result->content,
            'usage' => [
                'input_tokens' => $result->usage->inputTokens,
                'output_tokens' => $result->usage->outputTokens,
            ],
            'model' => $result->model,
            'finish_reason' => $result->finishReason->value,
            'meta' => [
                // Ollama 的 prompt_eval_count（模型實際處理的輸入 Token 數）；雲端則為 input_tokens
                'prompt_eval_count' => $result->usage->inputTokens,
                'document_pages' => $answer->context->pageCount,
                'document_chars' => $answer->context->chars,
                'estimated_tokens' => $answer->context->estimatedTokens,
                // null：此 Provider 無法判斷（雲端超過上限會直接回錯誤，不會靜默截斷）
                'truncated_suspected' => $result->inputTruncated,
                'duration_ms' => $answer->durationMs,
            ],
        ], options: JSON_UNESCAPED_UNICODE);
    }
}
