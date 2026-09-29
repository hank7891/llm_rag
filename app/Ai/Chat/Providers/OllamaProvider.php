<?php

namespace App\Ai\Chat\Providers;

use App\Ai\Chat\Contracts\ChatProviderInterface;
use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\ChatResult;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Chat\DTO\Usage;
use App\Ai\Exceptions\LlmResponseFormatException;
use App\Ai\Exceptions\LlmServerException;
use App\Ai\Support\JsonField;
use App\Ai\Support\LlmHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * Ollama 原生 /api/chat。system 直接放在 messages 中，角色名稱與內部格式相同。
 * 思考內容（message.thinking）一律捨棄，不混進回答。
 */
class OllamaProvider implements ChatProviderInterface
{
    private const NAME = 'ollama';

    /**
     * @param  array{base_url: string, model: ?string, num_ctx: int, think: bool, connect_timeout: int, timeout: int, stream_timeout: int}  $config
     */
    public function __construct(private readonly array $config) {}

    public function chat(array $messages, ChatOptions $options): ChatResult
    {
        $json = LlmHttp::postJson(self::NAME, $this->request(), '/api/chat', $this->body($messages, $options, stream: false));

        $finishReason = $this->finishReason($json);
        $content = JsonField::string(self::NAME, $json, 'message.content');
        $this->assertAnswered($content !== '', $finishReason);

        return new ChatResult(
            content: $content,
            usage: $this->usage($json),
            model: is_string($json['model'] ?? null) ? $json['model'] : $this->model($options),
            finishReason: $finishReason,
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

                yield new StreamChunk(
                    delta: $delta,
                    usage: $this->usage($event),
                    finishReason: $finishReason,
                    model: is_string($event['model'] ?? null) ? $event['model'] : $this->model($options),
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
            'options' => array_filter([
                'num_ctx' => $this->config['num_ctx'],
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
