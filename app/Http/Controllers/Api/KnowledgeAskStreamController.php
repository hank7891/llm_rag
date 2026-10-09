<?php

namespace App\Http\Controllers\Api;

use App\Http\AiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskKnowledgeRequest;
use App\Http\Streaming\SseRagProgressListener;
use App\Rag\Answer\AnswerOptions;
use App\Rag\Answer\QuerySource;
use App\Rag\Answer\RagAnswerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * RAG 串流問答（SSE）。流程與非串流的 /api/knowledge/ask 相同（同一個 RagAnswerService），只是逐階段送出進度；
 * 最後的 done 事件內容與非串流回應相同，是唯一的真相。驗證錯誤在串流開始前回 422。
 */
class KnowledgeAskStreamController extends Controller
{
    public function __invoke(AskKnowledgeRequest $request, RagAnswerService $rag, LoggerInterface $logger): StreamedResponse
    {
        $question = $request->validated('question');
        $options = new AnswerOptions($request->providerName(), QuerySource::Api, $request->conversationId());
        $timeLimit = config('rag.answer.stream_time_limit');

        return response()->stream(function () use ($rag, $logger, $question, $options, $timeLimit) {
            set_time_limit($timeLimit);
            // 使用者離開後仍繼續執行，才能停止讀取 LLM 串流並把這則回答標記為 interrupted
            ignore_user_abort(true);

            $send = function (string $event, array $data): void {
                echo "event: {$event}\ndata: ".json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                $answer = $rag->answer($question, $options, new SseRagProgressListener($send, fn () => connection_aborted() === 1));
                $send('done', $answer->toArray());
            } catch (Throwable $e) {
                // 例外訊息可能含上游位址或內部路徑：只寫進 log，事件只送錯誤類型與固定訊息
                $logger->error('rag.stream.failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
                $send('error', AiErrors::publicBody($e));
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            // 關閉 Nginx 等反向代理的緩衝，否則事件會累積到最後才一次送出
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
