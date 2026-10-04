<?php

namespace App\Ai\Embedding;

use App\Ai\Embedding\Contracts\EmbeddingProviderInterface;
use App\Ai\Embedding\DTO\EmbeddingOptions;
use App\Ai\Embedding\DTO\EmbeddingResult;
use App\Ai\Embedding\Exceptions\EmbeddingCountMismatchException;
use App\Ai\Embedding\Exceptions\EmbeddingDimensionMismatchException;
use App\Ai\Embedding\Exceptions\InvalidEmbeddingInputException;
use App\Ai\Embedding\Exceptions\UnknownEmbeddingProviderException;
use Illuminate\Contracts\Container\Container;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * 業務層產生向量的唯一入口：驗證輸入、依名稱取得 Provider，並在回傳前檢查「數量」與「維度」。
 * 不做前綴與格式轉換（Provider 的責任）。
 *
 * 數量與維度在這裡檢查，而不是等寫入 Qdrant 才發現：數量不符時向量會配錯 Chunk，
 * 寫進去之後就分不出哪個向量屬於哪一段；維度不符則會讓整批索引作廢。越早擋下，越容易追查。
 */
class EmbeddingService
{
    /**
     * @param  array<string, class-string<EmbeddingProviderInterface>>  $providers  provider 名稱 → 類別
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $providers,
        private readonly string $defaultProvider,
        private readonly EmbeddingModels $models,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param list<string> $texts */
    public function embed(array $texts, EmbeddingOptions $options, ?string $provider = null): EmbeddingResult
    {
        $this->validate($texts);
        $name = $provider ?? $this->defaultProvider;
        $startedAt = hrtime(true);

        $result = $this->provider($name)->embed($texts, $options);

        if (count($result->vectors) !== count($texts)) {
            throw EmbeddingCountMismatchException::of($name, count($texts), count($result->vectors));
        }

        $expected = $this->models->spec($result->model)['dimension'];

        foreach ($result->vectors as $vector) {
            if (count($vector) !== $expected) {
                throw EmbeddingDimensionMismatchException::of($result->model, $expected, count($vector));
            }
        }

        $this->logger->info('llm.embedding', [
            'provider' => $name,
            'model' => $result->model,
            'input_type' => $options->inputType->value,
            'texts' => count($texts),
            'input_tokens' => $result->inputTokens,
            'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
        ]);

        return $result;
    }

    private function provider(string $name): EmbeddingProviderInterface
    {
        $class = $this->providers[$name]
            ?? throw UnknownEmbeddingProviderException::named($name, array_keys($this->providers));

        $instance = $this->container->make($class);

        // 依能力區分：只實作 Chat 的 Provider 即使被誤登記，也會在這裡被擋下
        if (! $instance instanceof EmbeddingProviderInterface) {
            throw new LogicException(sprintf('Embedding provider [%s] (%s) must implement %s.', $name, $class, EmbeddingProviderInterface::class));
        }

        return $instance;
    }

    /** @param array<mixed> $texts */
    private function validate(array $texts): void
    {
        if ($texts === []) {
            throw InvalidEmbeddingInputException::empty();
        }

        foreach ($texts as $key => $text) {
            if (! is_string($text) || trim($text) === '') {
                throw InvalidEmbeddingInputException::blankText($key);
            }
        }
    }
}
