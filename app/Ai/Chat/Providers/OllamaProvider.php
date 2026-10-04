<?php

namespace App\Ai\Chat\Providers;

use App\Ai\Chat\Contracts\ChatProviderInterface;
use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\ChatResult;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Chat\DTO\Usage;
use App\Ai\Embedding\Contracts\EmbeddingProviderInterface;
use App\Ai\Embedding\DTO\EmbeddingInputType;
use App\Ai\Embedding\DTO\EmbeddingOptions;
use App\Ai\Embedding\DTO\EmbeddingResult;
use App\Ai\Embedding\EmbeddingModels;
use App\Ai\Embedding\Exceptions\EmbeddingCountMismatchException;
use App\Ai\Embedding\Exceptions\EmbeddingInputTooLongException;
use App\Ai\Exceptions\LlmClientException;
use App\Ai\Exceptions\LlmResponseFormatException;
use App\Ai\Exceptions\LlmServerException;
use App\Ai\Support\JsonField;
use App\Ai\Support\LlmHttp;
use App\Ai\Support\TokenEstimator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * Ollama：Chat 使用原生 /api/chat（system 直接放在 messages 中，思考內容一律捨棄）；
 * Embedding 使用 /api/embed（Ch06）。Ollama 兩種能力都有，所以同時實作兩個介面。
 */
class OllamaProvider implements ChatProviderInterface, EmbeddingProviderInterface
{
    private const NAME = 'ollama';

    /**
     * @param  array{base_url: string, model: ?string, embedding_model?: string, embedding_batch_size?: int, embedding_num_ctx?: int, num_ctx: int, think: bool, truncate: bool, connect_timeout: int, timeout: int, stream_timeout: int}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly EmbeddingModels $embeddingModels = new EmbeddingModels([]),
    ) {}

    /**
     * 依 batch_size 分批送出，合併時維持原始順序。每一批都檢查「送幾段、回幾個向量」，
     * 不一致就停下來，避免向量配錯段落。
     */
    public function embed(array $texts, EmbeddingOptions $options): EmbeddingResult
    {
        $model = $options->model ?? $this->config['embedding_model'] ?? throw new LogicException('[ollama] No embedding model configured (OLLAMA_EMBED_MODEL).');
        $spec = $this->embeddingModels->spec($model);
        $prefix = $options->inputType === EmbeddingInputType::Query ? $spec['query_prefix'] : $spec['document_prefix'];
        // num_ctx / num_batch 決定單段輸入的上限（Ollama 預設約 2048）；是記憶體與餘裕的取捨，不必開到模型最大值
        $context = $this->config['embedding_num_ctx'] ?? 2048;

        $vectors = [];
        $tokens = 0;
        $returnedModel = $model;

        foreach (array_chunk($texts, max(1, $this->config['embedding_batch_size'] ?? 16)) as $batch) {
            $json = $this->postEmbed([
                'model' => $model,
                'input' => array_map(fn (string $text) => $prefix.$text, $batch),
                // false：超過上限時回 HTTP 400，而不是截斷後照樣回傳 HTTP 200（Ollama 預設會截斷）
                'truncate' => false,
                'options' => ['num_ctx' => $context, 'num_batch' => $context],
            ], $model, $context);

            $embeddings = $json['embeddings'] ?? null;

            if (! is_array($embeddings) || ! array_is_list($embeddings)) {
                throw new LlmResponseFormatException('[ollama] Missing or invalid field [embeddings].');
            }

            if (count($embeddings) !== count($batch)) {
                throw EmbeddingCountMismatchException::of(self::NAME, count($batch), count($embeddings));
            }

            foreach ($embeddings as $vector) {
                $vectors[] = array_map('floatval', is_array($vector) ? $vector : []);
            }

            $tokens += JsonField::tokenCount(self::NAME, $json, 'prompt_eval_count');
            $returnedModel = is_string($json['model'] ?? null) ? $json['model'] : $model;
        }

        return new EmbeddingResult($vectors, $returnedModel, count($vectors[0] ?? []), $tokens);
    }

    /**
     * 呼叫 /api/embed，並把「超過長度」轉成明確的例外。實測錯誤訊息為 HTTP 400
     * 「the input length exceeds the context length」，不含 Token 數。
     *
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     */
    private function postEmbed(array $body, string $model, int $context): array
    {
        try {
            return LlmHttp::postJson(self::NAME, $this->request(), '/api/embed', $body);
        } catch (LlmClientException $e) {
            if (str_contains($e->getMessage(), 'exceeds the context length')) {
                throw new EmbeddingInputTooLongException(
                    "[ollama] Embedding input exceeds {$context} tokens for model [{$model}]; check the chunk size limit (config/rag.php).",
                    previous: $e,
                );
            }

            throw $e;
        }
    }

    public function chat(array $messages, ChatOptions $options): ChatResult
    {
        $json = LlmHttp::postJson(self::NAME, $this->request(), '/api/chat', $this->body($messages, $options, stream: false));

        $finishReason = $this->finishReason($json);
        $content = JsonField::string(self::NAME, $json, 'message.content');
        $this->assertAnswered($content !== '', $finishReason);

        $usage = $this->usage($json);

        return new ChatResult(
            content: $content,
            usage: $usage,
            model: is_string($json['model'] ?? null) ? $json['model'] : $this->model($options),
            finishReason: $finishReason,
            inputTruncated: $this->inputTruncated($messages, $options, $usage),
        );
    }

    public function stream(array $messages, ChatOptions $options): iterable
    {
        $lines = LlmHttp::streamLines(
            self::NAME,
            $this->request(),
            '/api/chat',
            $this->body($messages, $options, stream: true),
            $this->config['stream_timeout'],
        );
        $answered = false;

        foreach ($lines as $line) {
            $event = json_decode($line, true);

            if (! is_array($event)) {
                throw new LlmResponseFormatException('[ollama] Stream line is not a JSON object.');
            }

            // 串流開始後（HTTP 200）才發生的錯誤會以 {"error": "..."} 的一行送出
            if (isset($event['error'])) {
                throw new LlmServerException('[ollama] Stream error: '.(is_string($event['error']) ? $event['error'] : 'unknown'));
            }

            // 開啟 think 時，思考階段的 content 為空字串，只有 message.thinking 有值
            $delta = JsonField::string(self::NAME, $event, 'message.content');
            $answered = $answered || $delta !== '';

            if (($event['done'] ?? false) === true) {
                $finishReason = $this->finishReason($event);
                $this->assertAnswered($answered, $finishReason);

                $usage = $this->usage($event);

                yield new StreamChunk(
                    delta: $delta,
                    usage: $usage,
                    finishReason: $finishReason,
                    model: is_string($event['model'] ?? null) ? $event['model'] : $this->model($options),
                    inputTruncated: $this->inputTruncated($messages, $options, $usage),
                );

                return;
            }

            if ($delta !== '') {
                yield new StreamChunk($delta);
            }
        }

        throw new LlmResponseFormatException('[ollama] Stream ended before the done event.');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->acceptJson()
            ->connectTimeout($this->config['connect_timeout'])
            ->timeout($this->config['timeout'])
            // 串流時 timeout 管不到 body 讀取，read_timeout 限制「單次讀取」最多等多久
            ->withOptions(['read_timeout' => $this->config['timeout']]);
    }

    /** @param list<Message> $messages */
    private function body(array $messages, ChatOptions $options, bool $stream): array
    {
        return [
            'model' => $this->model($options),
            'messages' => array_map(fn (Message $m) => ['role' => $m->role->value, 'content' => $m->content], $messages),
            'stream' => $stream,
            'think' => $this->config['think'],
            // false：超過 num_ctx 時回 HTTP 400（附精確 Token 數），而不是靜默截斷
            'truncate' => $this->truncate($options),
            'options' => array_filter([
                // 一律明確帶入：Ollama 預設的 num_ctx 遠小於模型上限，超過時會靜默截斷
                'num_ctx' => $this->numCtx($options),
                'temperature' => $options->temperature,
                'num_predict' => $options->maxTokens,
            ], fn ($value) => $value !== null),
        ];
    }

    private function model(ChatOptions $options): string
    {
        return $options->model ?? $this->config['model']
            ?? throw new LogicException('[ollama] No model configured (OLLAMA_CHAT_MODEL).');
    }

    private function numCtx(ChatOptions $options): int
    {
        return (int) ($options->forProvider(self::NAME)['num_ctx'] ?? $this->config['num_ctx']);
    }

    private function truncate(ChatOptions $options): bool
    {
        return (bool) ($options->forProvider(self::NAME)['truncate'] ?? $this->config['truncate']);
    }

    /**
     * 判斷輸入是否被靜默截斷。實測（Ch04）：輸入超過 num_ctx 時，Ollama 從前面砍掉內容，
     * 只保留約 num_ctx 的一半，所以 prompt_eval_count 不會「接近 num_ctx」，單看它無法判斷。
     * 改以兩個條件同時成立判斷：粗估 Token 數超過 num_ctx，且實際處理的 Token 數明顯少於粗估值。
     *
     * @param  list<Message>  $messages
     */
    private function inputTruncated(array $messages, ChatOptions $options, Usage $usage): bool
    {
        if (! $this->truncate($options)) {
            return false; // truncate=false 時超過上限會直接報錯，不可能被截斷
        }

        $estimated = array_sum(array_map(fn (Message $m) => TokenEstimator::estimate($m->content), $messages));

        return $estimated > $this->numCtx($options) && $usage->inputTokens < $estimated * 0.8;
    }

    private function usage(array $json): Usage
    {
        return new Usage(
            inputTokens: JsonField::tokenCount(self::NAME, $json, 'prompt_eval_count'),
            outputTokens: JsonField::tokenCount(self::NAME, $json, 'eval_count'),
        );
    }

    private function finishReason(array $json): FinishReason
    {
        return match ($json['done_reason'] ?? null) {
            'stop' => FinishReason::Stop,
            'length' => FinishReason::Length,
            default => FinishReason::Other,
        };
    }

    /** 正常結束卻沒有任何回答文字視為異常；長度截斷（例如只思考就用完額度）則允許空內容。 */
    private function assertAnswered(bool $answered, FinishReason $finishReason): void
    {
        if (! $answered && $finishReason === FinishReason::Stop) {
            throw new LlmResponseFormatException('[ollama] Finished normally but returned no answer text.');
        }
    }
}
