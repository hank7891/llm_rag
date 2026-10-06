<?php

namespace App\Rag\Answer;

use App\Ai\Chat\ChatService;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Rag\Retrieval\RetrieveOptions;
use App\Rag\Retrieval\RetrieverService;
use App\Repositories\RagQueryLogRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 基本 RAG：檢索 → 沒有候選就直接回答資料不足 → 組參考資料 → LLM 回答。
 * 只依賴 RetrieverService 與 ChatService，不知道底層是哪個向量資料庫或供應商。
 */
class RagAnswerService
{
    public function __construct(
        private readonly RetrieverService $retriever,
        private readonly ChatService $chat,
        private readonly ReferenceContextBuilder $contexts,
        private readonly string $systemPrompt,
        private readonly int $topK,
        private readonly int $contextBudgetChars,
        private readonly string $insufficientMessage,
        private readonly ?string $defaultProvider,
        private readonly RagQueryLogRepository $logs,
        private readonly LoggerInterface $logger,
    ) {}

    public function answer(string $question, AnswerOptions $options = new AnswerOptions): RagAnswer
    {
        $answer = $this->generate($question, $options);
        $this->record($question, $answer, $options->source);

        return $answer;
    }

    private function generate(string $question, AnswerOptions $options): RagAnswer
    {
        $provider = $options->provider ?? $this->defaultProvider ?? $this->chat->defaultProvider();

        $startedAt = hrtime(true);
        $retrieval = $this->retriever->retrieve($question, new RetrieveOptions(topK: $this->topK));
        $retrievalMs = $this->elapsedMs($startedAt);

        // 第一道防線：沒有候選通過門檻就不呼叫 LLM，模型沒有機會依自己的記憶硬湊答案，也省下時間與成本
        if (! $retrieval->hasCandidates()) {
            return new RagAnswer($this->insufficientMessage, AnswerStatus::InsufficientNoCandidates, false, [], 0, $provider, null, null, $retrievalMs, null, $retrieval);
        }

        $context = $this->contexts->build($retrieval->chunks, $this->contextBudgetChars);
        $messages = [
            new Message(Role::System, $this->systemPrompt),
            new Message(Role::User, "<reference>\n{$context->text}\n</reference>\n\n問題：{$question}"),
        ];

        $startedAt = hrtime(true);
        $result = $this->chat->chat($messages, provider: $provider);

        return new RagAnswer(
            $result->content,
            // 啟發式判斷：回答含有固定訊息就視為 LLM 判斷資料不足。
            // 限制：「部分資料不足」的回答（例如答了一半、另一半資料不足）也會被歸到這裡；模型換句話說（「文件未提及」）則判斷不到
            str_contains($result->content, $this->insufficientMessage) ? AnswerStatus::InsufficientByLlm : AnswerStatus::Answered,
            true,
            $context->references,
            $context->droppedChunks,
            $provider,
            $result->model,
            $result->usage,
            $retrievalMs,
            $this->elapsedMs($startedAt),
            $retrieval,
            $messages,
            $result->finishReason,
        );
    }

    /** 紀錄只是輔助資料：寫入失敗（例如資料庫中斷）不影響回答，只留下 log */
    private function record(string $question, RagAnswer $answer, QuerySource $source): void
    {
        try {
            $this->logs->record($question, $answer, $source);
        } catch (Throwable $e) {
            $this->logger->warning('rag_query_logs 寫入失敗', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
