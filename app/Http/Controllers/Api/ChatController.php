<?php

namespace App\Http\Controllers\Api;

use App\Ai\Chat\ChatService;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Exceptions\LlmException;
use App\Http\AiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChatRequest;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\StreamedEvent;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 只依賴 ChatService 與 DTO，不知道底層是哪一家 Provider，也不因 Provider 分支。
 */
class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    public function chat(ChatRequest $request): JsonResponse
    {
        $result = $this->chat->chat($request->chatMessages(), $request->chatOptions(), $request->providerName());

        return new JsonResponse([
            'answer' => $result->content,
            'usage' => [
                'input_tokens' => $result->usage->inputTokens,
                'output_tokens' => $result->usage->outputTokens,
            ],
            'model' => $result->model,
            'finish_reason' => $result->finishReason->value,
        ], options: JSON_UNESCAPED_UNICODE);
    }

    public function stream(ChatRequest $request): StreamedResponse
    {
        $chunks = $this->generator($this->chat->stream($request->chatMessages(), $request->chatOptions(), $request->providerName()));

        // 先取得第一段再送出 header：連不上、429 等錯誤仍能回傳正確的 HTTP 狀態碼；
        // 一旦開始串流就已回應 200，之後的錯誤只能以 error 事件告知
        $chunks->current();

        return response()->eventStream(function () use ($chunks) {
            try {
                for (; $chunks->valid(); $chunks->next()) {
                    yield $this->event($chunks->current());
                }
            } catch (LlmException $e) {
                yield new StreamedEvent('error', $this->json(['error' => AiErrors::body($e)]));
            }
        }, endStreamWith: null);
    }

    private function event(StreamChunk $chunk): StreamedEvent
    {
        if ($chunk->usage === null) {
            return new StreamedEvent('delta', $this->json(['delta' => $chunk->delta]));
        }

        return new StreamedEvent('done', $this->json([
            'delta' => $chunk->delta,
            'done' => true,
            'usage' => [
                'input_tokens' => $chunk->usage->inputTokens,
                'output_tokens' => $chunk->usage->outputTokens,
            ],
            'model' => $chunk->model,
            'finish_reason' => $chunk->finishReason?->value,
        ]));
    }

    /** @param iterable<StreamChunk> $chunks */
    private function generator(iterable $chunks): Generator
    {
        yield from $chunks;
    }

    /** 自行編碼：eventStream 預設的 Js::encode 會把中文轉成 \uXXXX，不利於閱讀與除錯 */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
