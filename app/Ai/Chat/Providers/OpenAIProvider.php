<?php

namespace App\Ai\Chat\Providers;

use App\Ai\Chat\Contracts\ChatProviderInterface;
use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\ChatResult;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
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
 * OpenAI Responses API（/v1/responses），不使用 Chat Completions。
 * 開頭的 system 訊息抽出串接到頂層 instructions，其餘訊息放進 input。
 * 截斷與拒答不在結束原因欄位，而是由 status / incomplete_details / refusal 內容表示。
 */
class OpenAIProvider implements ChatProviderInterface
{
    private const NAME = 'openai';

    /**
     * @param  array{base_url: string, api_key: ?string, model: ?string, default_max_tokens: int, connect_timeout: int, timeout: int, stream_timeout: int}  $config
     */
    public function __construct(private readonly array $config) {}

    public function chat(array $messages, ChatOptions $options): ChatResult
    {
        $json = LlmHttp::postJson(self::NAME, $this->request($options->timeout), '/responses', $this->body($messages, $options, stream: false));

        return $this->result($json, $options);
    }

    public function stream(array $messages, ChatOptions $options): iterable
    {
        $lines = LlmHttp::streamLines(
            self::NAME,
            $this->request(),
            '/responses',
            $this->body($messages, $options, stream: true),
            $this->config['stream_timeout'],
        );

        foreach ($lines as $line) {
            // SSE 的 event: 行與 data 內的 type 重複，只解析 data: 行
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $event = json_decode(trim(substr($line, 5)), true);

            if (! is_array($event)) {
                throw new LlmResponseFormatException('[openai] Stream data is not a JSON object.');
            }

            switch ($event['type'] ?? null) {
                case 'response.output_text.delta':
                    $delta = JsonField::string(self::NAME, $event, 'delta');

                    if ($delta !== '') {
                        yield new StreamChunk($delta);
                    }
                    break;

                    // 終止事件帶完整 response 物件：用量、狀態、模型都從這裡取，與非串流共用同一套轉換
                case 'response.completed':
                case 'response.incomplete':
                    $final = $this->result(is_array($event['response'] ?? null) ? $event['response'] : [], $options);

                    yield new StreamChunk('', $final->usage, $final->finishReason, $final->model);

                    return;

                case 'response.failed':
                case 'error':
                    $detail = $event['response']['error']['message'] ?? $event['message'] ?? 'unknown';

                    throw new LlmServerException('[openai] Stream error: '.LlmHttp::redact(is_string($detail) ? $detail : 'unknown'));
            }
        }

        throw new LlmResponseFormatException('[openai] Stream ended before the completed event.');
    }

    private function request(?int $timeout = null): PendingRequest
    {
        $apiKey = $this->config['api_key']
            ?: throw new LogicException('[openai] No API key configured (OPENAI_API_KEY).');

        return Http::baseUrl($this->config['base_url'])
            ->withToken($apiKey)
            ->acceptJson()
            ->connectTimeout($this->config['connect_timeout'])
            ->timeout($timeout ?? $this->config['timeout'])
            // 串流時 timeout 管不到 body 讀取，read_timeout 限制「單次讀取」最多等多久
            ->withOptions(['read_timeout' => $timeout ?? $this->config['timeout']]);
    }

    /** @param list<Message> $messages */
    private function body(array $messages, ChatOptions $options, bool $stream): array
    {
        // ChatService 已保證 system 只出現在開頭，這裡直接依角色拆開
        $system = array_filter($messages, fn (Message $m) => $m->role === Role::System);
        $conversation = array_filter($messages, fn (Message $m) => $m->role !== Role::System);

        return array_filter([
            'model' => $options->model ?? $this->config['model']
                ?? throw new LogicException('[openai] No model configured (OPENAI_MODEL).'),
            'instructions' => $system === [] ? null : implode("\n\n", array_map(fn (Message $m) => $m->content, $system)),
            'input' => array_values(array_map(fn (Message $m) => ['role' => $m->role->value, 'content' => $m->content], $conversation)),
            'max_output_tokens' => $options->maxTokens ?? $this->config['default_max_tokens'],
            'temperature' => $options->temperature,
            // 對話歷史由我們自己管理，不使用 OpenAI 的 server-side conversation state
            'store' => false,
            'stream' => $stream,
        ], fn ($value) => $value !== null);
    }

    private function result(array $json, ChatOptions $options): ChatResult
    {
        $status = JsonField::string(self::NAME, $json, 'status');
        $output = $json['output'] ?? null;

        if (! is_array($output)) {
            throw new LlmResponseFormatException('[openai] Missing or invalid field [output].');
        }

        // output 可能含 reasoning 等其他類型，只取 message 內的 output_text；refusal 代表拒答
        $text = '';
        $refused = false;

        foreach ($output as $item) {
            if (($item['type'] ?? null) !== 'message' || ! is_array($item['content'] ?? null)) {
                continue;
            }

            foreach ($item['content'] as $part) {
                if (($part['type'] ?? null) === 'output_text') {
                    $text .= JsonField::string(self::NAME, $part, 'text');
                } elseif (($part['type'] ?? null) === 'refusal') {
                    $refused = true;
                }
            }
        }

        $finishReason = match (true) {
            $refused => FinishReason::ContentFilter,
            $status === 'completed' => FinishReason::Stop,
            $status === 'incomplete' => match ($json['incomplete_details']['reason'] ?? null) {
                'max_output_tokens' => FinishReason::Length,
                'content_filter' => FinishReason::ContentFilter,
                default => FinishReason::Other,
            },
            default => FinishReason::Other,
        };

        if ($text === '' && $finishReason === FinishReason::Stop) {
            throw new LlmResponseFormatException('[openai] Finished normally but returned no answer text.');
        }

        return new ChatResult(
            content: $text,
            usage: new Usage(
                inputTokens: JsonField::tokenCount(self::NAME, $json, 'usage.input_tokens'),
                outputTokens: JsonField::tokenCount(self::NAME, $json, 'usage.output_tokens'),
            ),
            model: is_string($json['model'] ?? null) ? $json['model'] : ($options->model ?? $this->config['model']),
            finishReason: $finishReason,
        );
    }
}
