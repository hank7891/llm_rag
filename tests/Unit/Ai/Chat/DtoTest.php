<?php

namespace Tests\Unit\Ai\Chat;

use App\Ai\Chat\DTO\ChatOptions;
use App\Ai\Chat\DTO\ChatResult;
use App\Ai\Chat\DTO\FinishReason;
use App\Ai\Chat\DTO\Message;
use App\Ai\Chat\DTO\Role;
use App\Ai\Chat\DTO\StreamChunk;
use App\Ai\Chat\DTO\Usage;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

class DtoTest extends TestCase
{
    /** @return array<string, array{string, Role}> */
    public static function roles(): array
    {
        return [
            'system' => ['system', Role::System],
            'user' => ['user', Role::User],
            'assistant' => ['assistant', Role::Assistant],
        ];
    }

    #[DataProvider('roles')]
    public function test_role_converts_from_string(string $value, Role $role): void
    {
        $this->assertSame($role, Role::from($value));
    }

    #[DataProvider('roles')]
    public function test_role_converts_to_string(string $value, Role $role): void
    {
        $this->assertSame($value, $role->value);
    }

    public function test_role_rejects_provider_specific_name(): void
    {
        $this->expectException(ValueError::class);

        Role::from('model');
    }

    public function test_message_holds_role_and_content(): void
    {
        $message = new Message(Role::User, '你好');

        $this->assertSame([Role::User, '你好'], [$message->role, $message->content]);
    }

    public function test_message_is_readonly(): void
    {
        $message = new Message(Role::User, '你好');

        $this->expectException(Error::class);

        $message->content = '改掉';
    }

    public function test_chat_options_default_to_null(): void
    {
        $options = new ChatOptions;

        $this->assertSame([null, null, null], [$options->model, $options->temperature, $options->maxTokens]);
    }

    public function test_chat_result_holds_usage(): void
    {
        $result = new ChatResult('答案', new Usage(inputTokens: 3, outputTokens: 5), 'qwen3:8b', FinishReason::Stop);

        $this->assertSame([3, 5], [$result->usage->inputTokens, $result->usage->outputTokens]);
    }

    public function test_stream_chunk_defaults_final_fields_to_null(): void
    {
        $chunk = new StreamChunk('片段');

        $this->assertSame([null, null, null], [$chunk->usage, $chunk->finishReason, $chunk->model]);
    }

    public function test_finish_reason_converts_to_string(): void
    {
        $this->assertSame(
            ['stop', 'length', 'content_filter', 'other'],
            array_map(fn (FinishReason $reason) => $reason->value, FinishReason::cases()),
        );
    }
}
