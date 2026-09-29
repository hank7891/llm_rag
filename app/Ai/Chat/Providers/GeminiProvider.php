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
use App\Ai\Support\JsonField;
use App\Ai\Support\LlmHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * Gemini generateContent API。system 抽到頂層 systemInstruction；assistant 角色改名為 model；
 * 內容包成 parts。思考內容（thought: true 的 part）捨棄。
 */
class GeminiProvider implements ChatProviderInterface
{
    private const NAME = 'gemini';

    /**
     * @param  array{base_url: string, api_key: ?string, model: ?string, default_max_tokens: int, connect_timeout: int, timeout: int, stream_timeout: int}  $config
     */
    public function __construct(private readonly array $config) {}

    public function chat(array $messages, ChatOptions $options): ChatResult
    {
        $json = LlmHttp::postJson(self::NAME, $this->request(), $this->model($options).':generateContent', $this->body($messages, $options));

        $finishReason = $this->finishReason($json);
        $content = $this->text($json);

        if ($content === '' && $finishReason === FinishReason::Stop) {
            throw new LlmResponseFormatException('[gemini] Finished normally but returned no answer text.');
        }

        return new ChatResult($content, $this->usage($json), $this->modelVersion($json, $options), $finishReason);
    }

    public function stream(array $messages, ChatOptions $options): iterable
    {
        $lines = LlmHttp::streamLines(
            self::NAME,
            $this->request(),
            $this->model($options).':streamGenerateContent?alt=sse',
            $this->body($messages, $options),
            $this->config['stream_timeout'],
        );
        $answered = false;

        foreach ($lines as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $event = json_decode(trim(substr($line, 5)), true);

            if (! is_array($event)) {
                throw new LlmResponseFormatException('[gemini] Stream data is not a JSON object.');
            }

            $delta = $this->text($event);
            $answered = $answered || $delta !== '';

            // Gemini 沒有獨立的結束事件：帶 finishReason 的那一段就是最後一段；usage 每段都是累計值，取最後一段即可
            if (! isset($event['candidates'][0]['finishReason']) && ! isset($event['promptFeedback']['blockReason'])) {
                if ($delta !== '') {
                    yield new StreamChunk($delta);
                }

                continue;
            }

            $finishReason = $this->finishReason($event);

            if (! $answered && $finishReason === FinishReason::Stop) {
                throw new LlmResponseFormatException('[gemini] Finished normally but returned no answer text.');
            }

            yield new StreamChunk($delta, $this->usage($event), $finishReason, $this->modelVersion($event, $options));

            return;
        }

        throw new LlmResponseFormatException('[gemini] Stream ended before a chunk with finishReason.');
    }

    private function request(): PendingRequest
    {
        $apiKey = $this->config['api_key']
            ?: throw new LogicException('[gemini] No API key configured (GEMINI_API_KEY).');

        // Key 放 header 而不是 ?key= 查詢參數：網址容易出現在 log 與例外訊息中
        return Http::baseUrl($this->config['base_url'])
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->acceptJson()
            ->connectTimeout($this->config['connect_timeout'])
            ->timeout($this->config['timeout'])
            // 串流時 timeout 管不到 body 讀取，read_timeout 限制「單次讀取」最多等多久
            ->withOptions(['read_timeout' => $this->config['timeout']]);
    }

    private function model(ChatOptions $options): string
    {
        return $options->model ?? $this->config['model']
            ?? throw new LogicException('[gemini] No model configured (GEMINI_MODEL).');
    }

    /** @param list<Message> $messages */
    private function body(array $messages, ChatOptions $options): array
    {
        // ChatService 已保證 system 只出現在開頭，這裡直接依角色拆開
        $system = array_filter($messages, fn (Message $m) => $m->role === Role::System);
        $conversation = array_filter($messages, fn (Message $m) => $m->role !== Role::System);

        return array_filter([
            'systemInstruction' => $system === [] ? null : [
                'parts' => [['text' => implode("\n\n", array_map(fn (Message $m) => $m->content, $system))]],
            ],
            'contents' => array_values(array_map(fn (Message $m) => [
                'role' => $m->role === Role::Assistant ? 'model' : 'user',
                'parts' => [['text' => $m->content]],
            ], $conversation)),
            'generationConfig' => array_filter([
                'maxOutputTokens' => $options->maxTokens ?? $this->config['default_max_tokens'],
                'temperature' => $options->temperature,
            ], fn ($value) => $value !== null),
        ], fn ($value) => $value !== null);
    }

    /** 串接第一個 candidate 的文字 part，略過思考內容（thought: true）。 */
    private function text(array $json): string
    {
        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        $text = '';

        foreach (is_array($parts) ? $parts : [] as $part) {
            if (($part['thought'] ?? false) !== true && array_key_exists('text', $part)) {
                $text .= JsonField::string(self::NAME, $part, 'text');
            }
        }

        return $text;
    }

    private function finishReason(array $json): FinishReason
    {
        // 提示詞本身被擋時沒有 candidates，只有 promptFeedback.blockReason
        if (isset($json['promptFeedback']['blockReason'])) {
            return FinishReason::ContentFilter;
        }

        return match ($json['candidates'][0]['finishReason'] ?? null) {
            'STOP' => FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII' => FinishReason::ContentFilter,
            default => FinishReason::Other,
        };
    }

    private function usage(array $json): Usage
    {
        return new Usage(
            inputTokens: JsonField::tokenCount(self::NAME, $json, 'usageMetadata.promptTokenCount'),
            // 思考 Token 也按輸出計費，併入 output 才能正確估算成本
            outputTokens: JsonField::tokenCount(self::NAME, $json, 'usageMetadata.candidatesTokenCount')
                + JsonField::tokenCount(self::NAME, $json, 'usageMetadata.thoughtsTokenCount'),
        );
    }

    private function modelVersion(array $json, ChatOptions $options): string
    {
        return is_string($json['modelVersion'] ?? null) ? $json['modelVersion'] : $this->model($options);
    }
}
