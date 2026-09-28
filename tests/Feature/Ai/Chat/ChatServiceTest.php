<?php

namespace Tests\Feature\Ai\Chat;

use App\Ai\Chat\ChatService;
use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Chat\Exceptions\InvalidMessagesException;
use App\Ai\Chat\Exceptions\UnknownChatProviderException;
use App\Ai\Chat\Providers\FakeChatProvider;
use LogicException;
use stdClass;
use Tests\Support\AltFakeChatProvider;
use Tests\TestCase;

class ChatServiceTest extends TestCase
{
    private FakeChatProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        // 綁定為固定實例，測試才能檢查 ChatService 實際交給 Provider 的內容
        $this->fake = new FakeChatProvider;
        $this->app->instance(FakeChatProvider::class, $this->fake);
    }

    /** @return list<Message> */
    private function threeTurns(): array
    {
        return [
            new Message(Role::User, '請簡單介紹 RAG'),
            new Message(Role::Assistant, 'RAG 是一種先檢索資料再生成答案的做法……'),
            new Message(Role::User, '那它跟微調有什麼不同？'),
        ];
    }

    private function service(): ChatService
    {
        return $this->app->make(ChatService::class);
    }

    public function test_three_turn_messages_reach_provider_unchanged(): void
    {
        $messages = $this->threeTurns();

        $this->service()->chat($messages);

        $this->assertSame($messages, $this->fake->lastMessages);
    }

    public function test_options_reach_provider_unchanged(): void
    {
        $options = new ChatOptions(model: 'qwen3:8b', temperature: 0.2, maxTokens: 100);

        $this->service()->chat($this->threeTurns(), $options);

        $this->assertSame($options, $this->fake->lastOptions);
    }

    public function test_missing_options_become_all_null_options(): void
    {
        $this->service()->chat($this->threeTurns());

        $this->assertEquals(new ChatOptions, $this->fake->lastOptions);
    }

    public function test_chat_returns_provider_result(): void
    {
        $result = $this->service()->chat($this->threeTurns());

        $this->assertSame('Echo: 那它跟微調有什麼不同？', $result->content);
    }

    public function test_switching_provider_by_name_needs_no_caller_change(): void
    {
        config()->set('llm.chat.providers.fake_alt', AltFakeChatProvider::class);
        $ask = fn (string $provider) => $this->service()->chat($this->threeTurns(), provider: $provider)->content;

        $this->assertSame(
            ['fake' => 'Echo: 那它跟微調有什麼不同？', 'fake_alt' => 'Alt: 那它跟微調有什麼不同？'],
            ['fake' => $ask('fake'), 'fake_alt' => $ask('fake_alt')],
        );
    }

    public function test_switching_default_provider_by_config_needs_no_caller_change(): void
    {
        config()->set('llm.chat.providers.fake_alt', AltFakeChatProvider::class);
        config()->set('llm.chat.default', 'fake_alt');

        $result = $this->service()->chat($this->threeTurns());

        $this->assertSame('Alt: 那它跟微調有什麼不同？', $result->content);
    }

    public function test_unknown_provider_lists_name_and_available_providers(): void
    {
        $this->expectException(UnknownChatProviderException::class);
        $this->expectExceptionMessage('Unknown chat provider [nope]. Available: fake.');

        $this->service()->chat($this->threeTurns(), provider: 'nope');
    }

    public function test_provider_not_implementing_interface_is_rejected(): void
    {
        config()->set('llm.chat.providers.broken', stdClass::class);

        $this->expectException(LogicException::class);

        $this->service()->chat($this->threeTurns(), provider: 'broken');
    }

    public function test_empty_messages_are_rejected(): void
    {
        $this->expectException(InvalidMessagesException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->service()->chat([]);
    }

    public function test_non_message_element_is_rejected(): void
    {
        $this->expectException(InvalidMessagesException::class);
        $this->expectExceptionMessage('Messages[0] is not an instance of Message');

        $this->service()->chat([['role' => 'user', 'content' => '你好']]);
    }

    public function test_last_message_not_from_user_is_rejected(): void
    {
        $this->expectException(InvalidMessagesException::class);
        $this->expectExceptionMessage('last message must have the user role');

        $this->service()->chat(array_slice($this->threeTurns(), 0, 2));
    }

    public function test_stream_chunks_join_into_full_answer(): void
    {
        $chunks = iterator_to_array($this->service()->stream($this->threeTurns()), false);

        $this->assertSame(
            $this->service()->chat($this->threeTurns())->content,
            implode('', array_map(fn (StreamChunk $chunk) => $chunk->delta, $chunks)),
        );
    }

    public function test_stream_yields_multiple_chunks(): void
    {
        $chunks = iterator_to_array($this->service()->stream($this->threeTurns()), false);

        $this->assertGreaterThan(1, count($chunks));
    }

    public function test_only_last_stream_chunk_carries_usage(): void
    {
        $chunks = iterator_to_array($this->service()->stream($this->threeTurns()), false);

        $this->assertSame(
            array_merge(array_fill(0, count($chunks) - 1, false), [true]),
            array_map(fn (StreamChunk $chunk) => $chunk->usage !== null, $chunks),
        );
    }

    public function test_stream_validates_before_iteration(): void
    {
        $this->expectException(InvalidMessagesException::class);

        // 刻意不迭代：驗證必須在呼叫當下完成，而非延後到開始讀取串流
        $this->service()->stream([]);
    }
}
