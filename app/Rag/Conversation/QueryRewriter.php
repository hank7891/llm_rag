<?php

namespace App\Rag\Conversation;

use App\Ai\Chat\ChatService;
use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Chat\DTO\Usage;
use App\Ai\Exceptions\LlmException;
use Psr\Log\LoggerInterface;

/**
 * Query Rewriting：依對話紀錄把追問改寫成不需要上下文也能理解的問題，再拿去檢索。
 * 錯誤發生在呼叫回答 LLM 之前（拿「那兩年年資呢？」去檢索就找不到特休），把歷史交給回答 LLM 救不了它。
 *
 * 沒有歷史時不改寫；改寫失敗一律降級為原問題，問答不中斷。
 */
class QueryRewriter
{
    /** 改寫跟隨本次實際使用的回答 Provider */
    public const FOLLOW = 'follow';

    /**
     * @param  string  $provider  follow 或 providers 中的名稱
     * @param  array<string, array{model: ?string, timeout: int, provider_options: array<string, mixed>}>  $providers  各 Provider 的改寫設定
     */
    public function __construct(
        private readonly ChatService $chat,
        private readonly LoggerInterface $logger,
        private readonly string $promptDirectory,
        private readonly string $promptVersion,
        private readonly string $provider,
        private readonly array $providers,
        private readonly int $maxChars,
        private readonly string $insufficientMessage,
    ) {}

    /**
     * @param  list<Turn>  $history  已經過 HistoryWindow（最近 N 輪、移除編號）
     * @param  string|null  $answerProvider  本次回答使用的 Provider（follow 時改寫跟著它）；null 時使用 Chat 的預設 Provider
     * @param  string|null  $provider  明確指定改寫的 Provider，覆寫設定值與 follow
     */
    public function rewrite(string $question, array $history, ?string $answerProvider = null, ?string $provider = null, ?string $promptVersion = null): RewriteResult
    {
        if ($history === []) {
            return new RewriteResult($question, RewriteStatus::Skipped, false);
        }

        $provider ??= $this->provider === self::FOLLOW ? ($answerProvider ?? $this->chat->defaultProvider()) : $this->provider;
        $settings = $this->providers[$provider] ?? null;

        // 不改用另一家 Provider：改寫會把對話內容送給它，不能因為缺設定就把資料送到回答流程以外的供應商
        if ($settings === null) {
            return $this->fallback($question, $provider, 'no_rewrite_config');
        }

        $startedAt = hrtime(true);

        try {
            $result = $this->chat->chat([
                new Message(Role::System, $this->prompt($promptVersion ?? $this->promptVersion)),
                new Message(Role::User, $this->userMessage($question, $history)),
            ], new ChatOptions(model: $settings['model'] ?? null, temperature: 0, providerOptions: [$provider => $settings['provider_options']], timeout: $settings['timeout']), $provider);
        } catch (LlmException $e) {
            return $this->fallback($question, $provider, $e::class.': '.$e->getMessage(), $startedAt);
        }

        $rewritten = $this->clean($result->content);
        $reason = match (true) {
            $rewritten === '' => 'empty',
            mb_strlen($rewritten) > $this->maxChars => 'too_long',
            // 輸出「資料不足」代表模型在回答問題，不是改寫
            str_contains($rewritten, $this->insufficientMessage) => 'looks_like_answer',
            default => null,
        };

        if ($reason !== null) {
            return $this->fallback($question, $provider, $reason, $startedAt, $result->content, $result->finishReason, $result->usage);
        }

        $status = $this->comparable($rewritten) === $this->comparable($question) ? RewriteStatus::Unchanged : RewriteStatus::Rewritten;

        return new RewriteResult($status === RewriteStatus::Unchanged ? $question : $rewritten, $status, true, $this->elapsedMs($startedAt), $result->usage, $result->content,
            finishReason: $result->finishReason, provider: $provider);
    }

    /** 這個 Provider 是否有改寫設定（沒有時追問一律以原問題檢索） */
    public function supports(string $provider): bool
    {
        return isset($this->providers[$provider]);
    }

    private function prompt(string $version): string
    {
        return file_get_contents("{$this->promptDirectory}/rag-rewrite-{$version}.md");
    }

    /**
     * 歷史放在 user 訊息的 <history> 標籤內（指令與資料分離）；內容中的 </history> 改成全形，避免提前結束標籤。
     *
     * @param  list<Turn>  $history
     */
    private function userMessage(string $question, array $history): string
    {
        $lines = [];
        foreach ($history as $turn) {
            $lines[] = '使用者：'.$this->neutralize($turn->question);
            $lines[] = '助理：'.$this->neutralize($turn->answer);
        }

        return "<history>\n".implode("\n", $lines)."\n</history>\n\n最後的問題：".$this->neutralize($question);
    }

    private function neutralize(string $text): string
    {
        return preg_replace('/<(\/?)history>/i', '＜$1history＞', $text);
    }

    /** 去掉模型常加的引號與「改寫後的問題：」等前綴 */
    private function clean(string $content): string
    {
        $text = trim(preg_replace('/^\s*(改寫後的問題|改寫後|問題)\s*[:：]\s*/u', '', trim($content)));

        // 不用 trim()：它以 byte 處理，會截斷「」這類多位元組字元
        return preg_replace('/^[\s「『"“\']+|[\s」』"”\']+$/u', '', $text);
    }

    private function comparable(string $text): string
    {
        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $text);
    }

    /** startedAt 為 null 代表沒有呼叫 LLM（例如該 Provider 沒有改寫設定） */
    private function fallback(string $question, string $provider, string $reason, ?int $startedAt = null, ?string $raw = null, ?FinishReason $finishReason = null, ?Usage $usage = null): RewriteResult
    {
        $this->logger->warning('rewrite.fallback', ['provider' => $provider, 'reason' => $reason, 'question' => $question]);

        return new RewriteResult($question, RewriteStatus::Fallback, $startedAt !== null, $startedAt === null ? null : $this->elapsedMs($startedAt), $usage, $raw, $reason, $finishReason, $provider);
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
