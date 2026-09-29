<?php

use App\Ai\Chat\Providers\FakeChatProvider;
use App\Ai\Chat\Providers\OllamaProvider;
use App\Ai\Chat\Providers\OpenAIProvider;

/*
 * LLM 抽象層設定。檔名不用 ai.php，避免與官方套件 laravel/ai 的 config/ai.php 衝突。
 */
return [

    'chat' => [

        // 呼叫端未指定 provider 時使用的名稱；fake 保留給測試
        'default' => env('LLM_CHAT_PROVIDER', 'ollama'),

        // provider 名稱 → 實作 ChatProviderInterface 的類別
        'providers' => [
            'ollama' => OllamaProvider::class,
            'openai' => OpenAIProvider::class,
            'fake' => FakeChatProvider::class,
        ],

    ],

    /*
     * 各供應商特有設定（不放進 ChatOptions）。放在 chat 之外，Ch06 的 Embedding 也會共用。
     * connect_timeout：建立連線的上限；timeout：非串流請求的總時間；stream_timeout：串流的總期限（秒）。
     */

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_CHAT_MODEL'),
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 8192),
        'think' => (bool) env('OLLAMA_THINK', false),
        'connect_timeout' => 5,
        'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
        'stream_timeout' => 300,
    ],

    'openai' => [
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL'),
        'default_max_tokens' => (int) env('OPENAI_MAX_TOKENS', 1024),
        'connect_timeout' => 5,
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        'stream_timeout' => 300,
    ],

    // Anthropic 尚未實作（Ch02 改採 OpenAI），設定先保留
    'anthropic' => [
        'base_url' => 'https://api.anthropic.com',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL'),
        'default_max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1024),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'connect_timeout' => 5,
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
        'stream_timeout' => 300,
    ],

];
