<?php

/*
 * RAG 流程的參數（切段，之後的檢索、Top-K 也放這裡）。改參數不需要改程式。
 */
return [

    'chunking' => [

        // structure：結構優先（章、條、標題）再遞迴切分；fixed：固定大小（實驗對照用）
        'strategy' => env('RAG_CHUNK_STRATEGY', 'structure'),

        // 單一 Chunk 的估算 Token 上限。必須遠低於 Embedding 模型的輸入上限（bge-m3 為 8192）
        'max_tokens' => (int) env('RAG_CHUNK_MAX_TOKENS', 600),

        // 同一段落被迫切開時，下一段開頭重疊的比例（對齊句子）；條與條之間不重疊
        'overlap_ratio' => (float) env('RAG_CHUNK_OVERLAP_RATIO', 0.15),

        // 低於此估算 Token 數的尾段併回前一段，避免過碎的 Chunk
        'min_tokens' => (int) env('RAG_CHUNK_MIN_TOKENS', 80),

        // Token 估算係數（字元數 × 係數）。刻意保守：Ch04 量到 qwen3 每個中文字約 0.75 Token，
        // 但切段是給 Ch06 的 bge-m3 用，Tokenizer 不同，先以 1.0 估算寧可切小，Ch06 實測後校正
        'token_estimate' => [
            'cjk_per_char' => 1.0,
            'other_per_char' => 0.3,
        ],

    ],

];
