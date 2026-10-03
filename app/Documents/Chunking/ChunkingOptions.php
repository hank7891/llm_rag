<?php

namespace App\Documents\Chunking;

/**
 * 切段參數。預設值來自 config/rag.php，實驗時可由指令逐次覆寫。
 */
final readonly class ChunkingOptions
{
    public const STRUCTURE = 'structure';

    public const FIXED = 'fixed';

    public function __construct(
        public string $strategy,
        public int $maxTokens,
        public float $overlapRatio,
        public int $minTokens,
    ) {}

    /** @param array{strategy: string, max_tokens: int, overlap_ratio: float, min_tokens: int} $config */
    public static function fromConfig(array $config): self
    {
        return new self($config['strategy'], $config['max_tokens'], $config['overlap_ratio'], $config['min_tokens']);
    }

    public function with(?string $strategy = null, ?int $maxTokens = null, ?float $overlapRatio = null): self
    {
        return new self($strategy ?? $this->strategy, $maxTokens ?? $this->maxTokens, $overlapRatio ?? $this->overlapRatio, $this->minTokens);
    }

    public function overlapTokens(): int
    {
        return (int) floor($this->maxTokens * $this->overlapRatio);
    }

    /** 寫入 document_chunks.chunk_strategy，例如 structure_v1:max600:ov15，Ch08 比較設定時用 */
    public function label(): string
    {
        return sprintf('%s_v1:max%d:ov%d', $this->strategy, $this->maxTokens, (int) round($this->overlapRatio * 100));
    }
}
