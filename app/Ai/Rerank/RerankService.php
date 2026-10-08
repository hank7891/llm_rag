<?php

namespace App\Ai\Rerank;

use App\Ai\Rerank\Contracts\RerankProviderInterface;
use App\Ai\Rerank\DTO\RerankOptions;
use App\Ai\Rerank\DTO\RerankResult;
use App\Ai\Rerank\Exceptions\UnknownRerankProviderException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * Rerank 的業務入口：依名稱取得 Provider、檢查輸入、記錄耗時。業務層不知道底層是 llama-server 還是雲端服務。
 */
class RerankService
{
    /** @param array<string, class-string<RerankProviderInterface>> $providers provider 名稱 → 類別 */
    public function __construct(
        private readonly Container $container,
        private readonly array $providers,
        private readonly string $defaultProvider,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param list<string> $documents */
    public function rerank(string $query, array $documents, RerankOptions $options = new RerankOptions, ?string $provider = null): RerankResult
    {
        if ($documents === [] || array_filter($documents, fn ($d) => ! is_string($d) || trim($d) === '') !== []) {
            throw new InvalidArgumentException('Rerank documents must be a non-empty list of non-blank strings.');
        }

        $name = $provider ?? $this->defaultProvider;
        $result = $this->provider($name)->rerank($query, array_values($documents), $options);

        $this->logger->info('llm.rerank', ['provider' => $name, 'model' => $result->model, 'documents' => count($documents), 'duration_ms' => $result->latencyMs]);

        return $result;
    }

    private function provider(string $name): RerankProviderInterface
    {
        $class = $this->providers[$name] ?? throw UnknownRerankProviderException::named($name, array_keys($this->providers));
        $instance = $this->container->make($class);

        if (! $instance instanceof RerankProviderInterface) {
            throw new LogicException(sprintf('Rerank provider [%s] (%s) must implement %s.', $name, $class, RerankProviderInterface::class));
        }

        return $instance;
    }
}
