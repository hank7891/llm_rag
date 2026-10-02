<?php

namespace App\Documents\Qa;

use App\Ai\Chat\DTO\ChatResult;

/**
 * 文件問答的結果：模型的回答，加上觀察實驗用的統計資訊。
 */
final readonly class DocumentAnswer
{
    public function __construct(
        public ChatResult $result,
        public DocumentContext $context,
        public int $durationMs,
    ) {}
}
