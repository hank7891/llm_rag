<?php

namespace App\Providers;

use App\Ai\Chat\ChatService;
use App\Ai\Chat\Providers\OllamaProvider;
use App\Ai\Chat\Providers\OpenAIProvider;
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

        $this->app->bind(OllamaProvider::class, fn ($app) => new OllamaProvider($app['config']->get('llm.ollama')));
        $this->app->bind(OpenAIProvider::class, fn ($app) => new OpenAIProvider($app['config']->get('llm.openai')));
    }
}
