<?php

/*
 * RAG 流程的參數（切段、檢索、向量資料庫）。改參數不需要改程式。
 */
return [

    'chunking' => [

        // structure：結構優先（章、條、標題）再遞迴切分；fixed：固定大小（實驗對照用）
        'strategy' => env('RAG_CHUNK_STRATEGY', 'structure'),

        // 單一 Chunk 的估算 Token 上限。必須遠低於 Embedding 模型的輸入上限（bge-m3 為 8192）
        'max_tokens' => (int) env('RAG_CHUNK_MAX_TOKENS', 600),

        // 同一段落被迫切開時，下一段開頭重疊的比例（對齊句子）；條與條之間不重疊
        'overlap_ratio' => (float) env('RAG_CHUNK_OVERLAP_RATIO', 0.15),

        // 低於此估算 Token 數的尾段併回前一段、開頭段（通常只有文件標題）併入下一段，避免過碎的 Chunk
        'min_tokens' => (int) env('RAG_CHUNK_MIN_TOKENS', 80),

        // Token 估算係數（字元數 × 係數）。刻意保守：Ch04 量到 qwen3 每個中文字約 0.75 Token，
        // 但切段是給 Ch06 的 bge-m3 用，Tokenizer 不同，先以 1.0 估算寧可切小，Ch06 實測後校正
        'token_estimate' => [
            'cjk_per_char' => 1.0,
            'other_per_char' => 0.3,
        ],

    ],

    'retrieval' => [

        // 未指定時回傳的筆數
        'top_k' => (int) env('RAG_TOP_K', 5),

        // 相關度門檻，依 Embedding 模型分別設定（Cosine 分數的絕對值依模型而不同，不可沿用）。
        // 分數「大於」門檻才保留（Qdrant score_threshold 實測）。沒有設定門檻的模型不可直接檢索，
        // 避免沒有門檻、無關內容全部通過
        'score_thresholds' => [
            // Ch08 測試集（30 題）：無答案題 Top-1 最高 0.5665、有答案題（不含精確編號）最低 0.6106，
            // 取間隔中點（0.589）略偏高。理由與分數分布見 docs/notes/ch08-semantic-search.md
            'bge-m3' => 0.59,
            // 同一份測試集：無答案題 Top-1 最高 0.4691、有答案題（不含精確編號）最低 0.4899，取間隔中點
            'qwen3-embedding:0.6b' => 0.48,
        ],

    ],

    'qdrant' => [

        'url' => env('QDRANT_URL', 'http://localhost:6333'),

        // Collection 名稱 = 前綴 + "_" + Embedding 模型名稱（例如 company_docs_bge_m3）。
        // 名稱一律由模型推導，不另設 Collection 名稱：模型和 Collection 只有一個來源，就不會「模型換了、Collection 忘了換」
        'collection_prefix' => env('RAG_COLLECTION_PREFIX', 'company_docs'),

        // 每次 upsert 的 Point 數
        'upsert_batch_size' => 64,

        'timeout' => 30,

    ],

];
