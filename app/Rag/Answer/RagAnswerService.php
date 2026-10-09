<?php

namespace App\Rag\Answer;

use App\Ai\Chat\ChatService;
use App\Ai\Chat\DTO\ChatResult;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Exceptions\LlmResponseFormatException;
use App\Rag\Citation\CitationFormatter;
use App\Rag\Citation\CitationResolver;
use App\Rag\Conversation\HistoryWindow;
use App\Rag\Conversation\QueryRewriter;
use App\Rag\Conversation\Turn;
use App\Rag\Retrieval\RetrieveOptions;
use App\Rag\Retrieval\RetrieverService;
use App\Repositories\ConversationRepository;
use App\Repositories\RagQueryLogRepository;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 基本 RAG：檢索 → 沒有候選就直接回答資料不足 → 組參考資料 → LLM 回答。
 * 只依賴 RetrieverService 與 ChatService，不知道底層是哪個向量資料庫或供應商。
 *
 * 串流與非串流共用同一個流程：傳入 RagProgressListener 時逐階段通知、回答改用串流，產出的 RagAnswer 欄位與規則完全相同。
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
        private readonly CitationResolver $citations,
        private readonly CitationFormatter $formatter,
        private readonly ConversationRepository $conversations,
        private readonly HistoryWindow $window,
        private readonly QueryRewriter $rewriter,
        private readonly bool $conversationEnabled,
        private readonly int $windowTurns,
    ) {}

    public function answer(string $question, AnswerOptions $options = new AnswerOptions, ?RagProgressListener $listener = null): RagAnswer
    {
        [$conversationId, $history, $provider] = $this->conversation($options);
        $listener?->conversation($conversationId);
        $answer = $this->generate($question, $provider, $conversationId, $history, $listener);

        if ($conversationId !== null) {
            // 保存原始回答（含 [n]）；組歷史時才由 HistoryWindow 移除編號
            $this->conversations->addExchange($conversationId, $question, $answer->rewrite->question, $answer->rewrite->status, $answer->rawAnswer ?? $answer->answer, $answer->status);
        }

        $this->record($question, $answer, $options->source);

        return $answer;
    }

    /**
     * 決定這次問答屬於哪個對話、歷史與回答 Provider。對話紀錄存在 server 端：Client 只帶 conversation_id，不能自行偽造 assistant 訊息。
     * Provider 在建立對話時記下，同一段對話不可中途更換（改寫跟隨回答 Provider，換了等於換改寫模型與資料外送對象）。
     *
     * @return array{?int, list<Turn>, string}
     */
    private function conversation(AnswerOptions $options): array
    {
        $provider = $options->provider ?? $this->defaultProvider ?? $this->chat->defaultProvider();

        if (! $this->conversationEnabled) {
            return [null, [], $provider];
        }

        if ($options->history !== null) {
            return [null, $this->windowed($options->history, null), $provider];
        }

        if ($options->conversationId === null && $options->source === QuerySource::Eval) {
            return [null, [], $provider];
        }

        if ($options->conversationId === null) {
            return [$this->conversations->create($provider)->id, [], $provider];
        }

        $conversation = $this->conversations->find($options->conversationId)
            ?? throw new InvalidArgumentException("Conversation [{$options->conversationId}] not found.");

        // Ch13 建立的對話沒有記錄 Provider，不限制
        if ($conversation->provider !== null) {
            if ($options->provider !== null && $options->provider !== $conversation->provider) {
                throw new InvalidArgumentException("Conversation [{$conversation->id}] uses provider [{$conversation->provider}]; start a new conversation to switch providers.");
            }
            $provider = $conversation->provider;
        }

        return [$conversation->id, $this->windowed($this->conversations->recentTurns($conversation->id, $this->windowTurns), $conversation->id), $provider];
    }

    /**
     * 套用 Sliding Window，並記錄 history_budget_chars 實際保留的輪數（長回答會讓保留的輪數少於 window_turns）。
     *
     * @param  list<Turn>  $turns
     * @return list<Turn>
     */
    private function windowed(array $turns, ?int $conversationId): array
    {
        $kept = $this->window->apply($turns);

        if ($turns !== []) {
            $this->logger->info('conversation.history', [
                'conversation_id' => $conversationId,
                'window_turns' => $this->windowTurns,
                'in_window' => min(count($turns), $this->windowTurns),
                'kept' => count($kept),
                'chars' => array_sum(array_map(fn (Turn $t) => mb_strlen($t->question) + mb_strlen($t->answer), $kept)),
            ]);
        }

        return $kept;
    }

    /** @param list<Turn> $history 已經過 Sliding Window（最近 N 輪、移除編號、長度預算） */
    private function generate(string $question, string $provider, ?int $conversationId, array $history, ?RagProgressListener $listener): RagAnswer
    {
        // 錯誤發生在檢索之前：追問先改寫成獨立問題，Dense、關鍵字、Reranker 一律使用改寫後的問題（第一輪不改寫）
        $listener?->stage(RagStage::Rewriting);
        $rewrite = $this->rewriter->rewrite($question, $history, $provider);
        $listener?->rewrite($rewrite);

        $listener?->stage(RagStage::Retrieving);
        $startedAt = hrtime(true);
        $retrieval = $this->retriever->retrieve($rewrite->question, new RetrieveOptions(topK: $this->topK));
        $retrievalMs = $this->elapsedMs($startedAt);
        $listener?->retrieval($retrieval);

        // 第一道防線：沒有候選通過門檻就不呼叫 LLM，模型沒有機會依自己的記憶硬湊答案，也省下時間與成本
        if (! $retrieval->hasCandidates()) {
            return new RagAnswer($this->insufficientMessage, AnswerStatus::InsufficientNoCandidates, false, [], 0, $provider, null, null, $retrievalMs, null, $retrieval,
                conversationId: $conversationId, originalQuestion: $question, rewrite: $rewrite, historyTurns: count($history));
        }

        $context = $this->contexts->build($retrieval->chunks, $this->contextBudgetChars);
        // 回答：最近 N 輪（已移除編號）+ 參考資料 + 原始問題（保留使用者的語氣與指代）
        $messages = [
            new Message(Role::System, $this->systemPrompt),
            ...array_merge(...array_map(fn (Turn $t) => [new Message(Role::User, $t->question), new Message(Role::Assistant, $t->answer)], $history)),
            new Message(Role::User, "<reference>\n{$context->text}\n</reference>\n\n問題：{$question}"),
        ];

        $listener?->stage(RagStage::Generating);
        $startedAt = hrtime(true);
        $result = $listener === null ? $this->chat->chat($messages, provider: $provider) : $this->streamed($messages, $provider, $listener);
        $llmMs = $this->elapsedMs($startedAt);

        if (is_string($result)) {
            return new RagAnswer($result, AnswerStatus::Interrupted, true, $context->references, $context->droppedChunks, $provider, null, null, $retrievalMs, $llmMs, $retrieval, $messages,
                rawAnswer: $result, conversationId: $conversationId, originalQuestion: $question, rewrite: $rewrite, historyTurns: count($history));
        }

        $status = $this->isInsufficient($result->content) ? AnswerStatus::InsufficientByLlm : AnswerStatus::Answered;
        // 來源一律由程式依編號對照表與 MySQL 產生；資料不足的回答不顯示來源
        $cited = $this->citations->resolve($result->content, $context->references, $status->isInsufficient());

        return new RagAnswer(
            $cited->answer,
            $status,
            true,
            $context->references,
            $context->droppedChunks,
            $provider,
            $result->model,
            $result->usage,
            $retrievalMs,
            $llmMs,
            $retrieval,
            $messages,
            $result->finishReason,
            $result->content,
            $cited->citations,
            $this->formatter->lines($cited->citations),
            $cited->invalidRefs,
            $cited->uncited,
            collect($cited->citations)->mapWithKeys(fn ($c) => [$c->ref => $this->formatter->label($c)])->all(),
            $conversationId,
            $question,
            $rewrite,
            count($history),
        );
    }

    /**
     * 以串流取得回答，逐段交給 Listener；最後一段（帶 usage）組回與非串流相同的 ChatResult。
     * 呼叫端中斷時停止讀取（Generator 結束後連線關閉，供應商停止生成），回傳目前收到的部分文字。
     *
     * @param  list<Message>  $messages
     */
    private function streamed(array $messages, string $provider, RagProgressListener $listener): ChatResult|string
    {
        $content = '';

        foreach ($this->chat->stream($messages, provider: $provider) as $chunk) {
            $content .= $chunk->delta;

            if ($chunk->delta !== '') {
                $listener->delta($chunk->delta);
            }

            if ($chunk->usage !== null) {
                return new ChatResult($content, $chunk->usage, $chunk->model ?? $provider, $chunk->finishReason ?? FinishReason::Other, $chunk->inputTruncated);
            }

            // 斷線要等寫出資料後才偵測得到，所以在送出 delta 之後檢查
            if ($listener->cancelled()) {
                return $content;
            }
        }

        throw new LlmResponseFormatException("[{$provider}] Stream ended without a final chunk.");
    }

    /**
     * 啟發式判斷：回答（去掉引用標記與開頭標點）以固定訊息開頭，視為 LLM 判斷資料不足。
     * Ch09 用「含有」判斷，會把「先回答一部分、最後說另一部分資料不足」的回答誤判為不足，連帶清掉合法的來源；
     * 改為「開頭」後這類回答維持 answered。限制：換句話說的拒答（「無法回答」「並未提及」）仍判斷不到。
     */
    private function isInsufficient(string $content): bool
    {
        $text = preg_replace('/[\[［][^\]］\n]{1,20}[\]］]/u', '', $content);

        return str_starts_with(preg_replace('/^[\s「『*#\x{3000}]+/u', '', $text), $this->insufficientMessage);
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
