<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AskKnowledgeRequest;
use App\Rag\Answer\AnswerOptions;
use App\Rag\Answer\QuerySource;
use App\Rag\Answer\RagAnswerService;
use Illuminate\Http\JsonResponse;

/**
 * 知識庫問答（RAG）。Prompt 與流程都在 RagAnswerService，Controller 只轉換輸入與輸出，不因 Provider 分支。
 */
class KnowledgeAskController extends Controller
{
    public function __invoke(AskKnowledgeRequest $request, RagAnswerService $rag): JsonResponse
    {
        $answer = $rag->answer($request->validated('question'), new AnswerOptions($request->providerName(), QuerySource::Api));

        return new JsonResponse($answer->toArray(), options: JSON_UNESCAPED_UNICODE);
    }
}
