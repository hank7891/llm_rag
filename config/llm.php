<?php

use App\Ai\Chat\Providers\FakeChatProvider;
use App\Ai\Chat\Providers\GeminiProvider;
use App\Ai\Chat\Providers\OllamaProvider;
use App\Ai\Chat\Providers\OpenAIProvider;
use App\Ai\Embedding\Providers\FakeEmbeddingProvider;

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
            'gemini' => GeminiProvider::class,
            'fake' => FakeChatProvider::class,
        ],

    ],

    'embedding' => [

        // 提問與建索引必須用同一個 provider 與模型；更換模型等於全部重建索引（新舊向量不能混用）
        'default' => env('LLM_EMBEDDING_PROVIDER', 'ollama'),

        // provider 名稱 → 實作 EmbeddingProviderInterface 的類別。只支援 Chat 的供應商不登記在這裡
        'providers' => [
            'ollama' => OllamaProvider::class,
            'fake' => FakeEmbeddingProvider::class,
        ],

        // 模型規格（由模型決定，不是部署設定，所以不放 .env）。數值以 Ch06 實測確認：
        // dimension：向量維度，Ch07 建立 Qdrant Collection 的 size 必須相同
        // max_tokens：最大輸入長度，Chunk 必須小於它
        // query_prefix / document_prefix：模型要求的前綴，由 Provider 依 inputType 加上
        'models' => [
            'bge-m3' => ['dimension' => 1024, 'max_tokens' => 8192, 'query_prefix' => '', 'document_prefix' => ''],
            'qwen3-embedding:0.6b' => [
                'dimension' => 1024,
                'max_tokens' => 32768,
                // 模型說明：查詢需加任務指令，文件不加
                'query_prefix' => "Instruct: Given a web search query, retrieve relevant passages that answer the query\nQuery:",
                'document_prefix' => '',
            ],
            'fake' => ['dimension' => FakeEmbeddingProvider::DIMENSION, 'max_tokens' => 8192, 'query_prefix' => '', 'document_prefix' => ''],
        ],

    ],

    /*
     * 各供應商特有設定（不放進 ChatOptions）。放在 chat 之外，Ch06 的 Embedding 也會共用。
     * connect_timeout：建立連線的上限；timeout：非串流請求的總時間；stream_timeout：串流的總期限（秒）。
     */

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_CHAT_MODEL'),
        'embedding_model' => env('OLLAMA_EMBED_MODEL', 'bge-m3'),
        // 每次送給 /api/embed 的段數，主要受這台機器的記憶體影響
        'embedding_batch_size' => (int) env('OLLAMA_EMBED_BATCH_SIZE', 16),
        // Embedding 的 num_ctx 與 num_batch 上限（實測：不設定時 Ollama 只處理約 2048 Token 就截斷，
        // 即使 bge-m3 支援 8192）。實際使用 min(模型上限, 此值)；開越大越吃記憶體
        'embedding_num_ctx' => (int) env('OLLAMA_EMBED_NUM_CTX', 8192),
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 8192),
        'think' => (bool) env('OLLAMA_THINK', false),
        // true（Ollama 預設）：超過 num_ctx 時靜默截斷，從前面砍掉內容；false：直接回錯誤
        'truncate' => (bool) env('OLLAMA_TRUNCATE', true),
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

    'gemini' => [
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL'),
        'default_max_tokens' => (int) env('GEMINI_MAX_TOKENS', 1024),
        'connect_timeout' => 5,
        'timeout' => (int) env('GEMINI_TIMEOUT', 60),
        'stream_timeout' => 300,
    ],

];
