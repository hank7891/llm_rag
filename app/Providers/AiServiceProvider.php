<?php

namespace App\Providers;

use App\Ai\Chat\ChatService;
use App\Ai\Chat\Providers\GeminiProvider;
use App\Ai\Chat\Providers\OllamaProvider;
use App\Ai\Chat\Providers\OpenAIProvider;
use App\Ai\Embedding\EmbeddingModels;
use App\Ai\Embedding\EmbeddingService;
use App\Ai\Rerank\Providers\CohereCompatibleRerankProvider;
use App\Ai\Rerank\RerankService;
use Illuminate\Support\ServiceProvider;

/**
 * AI 相關元件的組裝處：唯一讀取 config/llm.php 的地方，其他類別只接收組好的參數。
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChatService::class, fn ($app) => new ChatService(
            $app,
            $app['config']->get('llm.chat.providers', []),
            $app['config']->get('llm.chat.default'),
            $app['log'],
        ));

        $this->app->singleton(EmbeddingModels::class, fn ($app) => new EmbeddingModels($app['config']->get('llm.embedding.models', [])));

        $this->app->singleton(EmbeddingService::class, fn ($app) => new EmbeddingService(
            $app,
            $app['config']->get('llm.embedding.providers', []),
            $app['config']->get('llm.embedding.default'),
            $app->make(EmbeddingModels::class),
            $app['log'],
        ));

        $this->app->singleton(RerankService::class, fn ($app) => new RerankService(
            $app,
            $app['config']->get('llm.rerank.providers', []),
            $app['config']->get('llm.rerank.default'),
            $app['log'],
        ));

        $this->app->bind(CohereCompatibleRerankProvider::class, fn ($app) => new CohereCompatibleRerankProvider(['name' => 'llamacpp'] + $app['config']->get('llm.rerank.llamacpp')));

        $this->app->bind(OllamaProvider::class, fn ($app) => new OllamaProvider($app['config']->get('llm.ollama'), $app->make(EmbeddingModels::class)));
        $this->app->bind(OpenAIProvider::class, fn ($app) => new OpenAIProvider($app['config']->get('llm.openai')));
        $this->app->bind(GeminiProvider::class, fn ($app) => new GeminiProvider($app['config']->get('llm.gemini')));
    }
}
