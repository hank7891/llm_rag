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

        // dense（Ch08）/ keyword / hybrid（Ch11：向量 + 關鍵字，以 RRF 合併）
        'mode' => env('RAG_RETRIEVAL_MODE', 'hybrid'),

        // 只被一般關鍵字找到的 Chunk 能否進入結果：exact_only（只有精確命中可以）/ allow
        'keyword_only_policy' => env('RAG_KEYWORD_ONLY_POLICY', 'exact_only'),

        // Hybrid 時各路先取的候選數（Dense 為套門檻後），合併後再取 Top-K
        'dense_candidates' => (int) env('RAG_DENSE_CANDIDATES', 20),
        'keyword_candidates' => (int) env('RAG_KEYWORD_CANDIDATES', 20),

        // RRF 常數：分數 = Σ 1 / (k + 名次)。k 越大名次差距越小，60 為常用預設
        'rrf_k' => (int) env('RAG_RRF_K', 60),

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

    'rerank' => [

        // 第二階段重排（Ch12，Cross-encoder bge-reranker-v2-m3 on llama-server）。評估後由使用者決定才改成 true；
        // false 時檢索結果與 Ch11 完全相同。連線設定（位址、模型、逾時）在 config/llm.php 的 rerank 區塊
        'enabled' => (bool) env('RAG_RERANK_ENABLED', false),

        // 送進 Reranker 的候選數。延遲大致與候選總字數成正比（實測 5 / 10 / 20 段約 0.6 / 1.2 / 2.8 秒）
        'candidates' => (int) env('RAG_RERANK_CANDIDATES', 20),

        // 精確命中（編號、條號）的段落保證保留在最終結果中，不會被 Reranker 擠掉
        'keep_exact' => (bool) env('RAG_RERANK_KEEP_EXACT', true),

        // 送出時在段落前加上「文件名稱 條號：」
        'prefix_metadata' => (bool) env('RAG_RERANK_PREFIX_METADATA', false),

        // 實驗用：低於此分數的段落不採用（logit，與 Cosine 尺度不同）。預設 null 不啟用，第一版不用 Reranker 分數判斷有無資料
        'min_score' => env('RAG_RERANK_MIN_SCORE') === null ? null : (float) env('RAG_RERANK_MIN_SCORE'),

    ],

    'conversation' => [

        // 多輪對話（Ch13）：檢索前先把追問改寫成獨立問題，對話紀錄以 Sliding Window 保留最近 N 輪。
        // false 時忽略 conversation_id 與歷史，行為與 Ch12 的單輪問答相同
        'enabled' => (bool) env('RAG_CONVERSATION_ENABLED', true),

        // 保留最近幾輪（一輪 = 一問一答）。Ch13 實驗 4：3 輪時回指第 1 輪的追問會被對到 Window 中錯的輪次，且沒有任何錯誤訊號
        'window_turns' => (int) env('RAG_CONVERSATION_WINDOW_TURNS', 5),

        // 歷史的總長度上限（字元數）。歷史、參考資料（answer.context_budget_chars）、回答共用 num_ctx 8192；
        // 超過時從最舊的一輪開始捨去。改寫與回答都套用
        'history_budget_chars' => (int) env('RAG_CONVERSATION_HISTORY_BUDGET_CHARS', 1500),

        'rewrite' => [
            // 改寫用的 Chat Provider。follow：跟隨本次實際使用的回答 Provider（含 API 參數、rag:ask --provider 逐次指定的值），
            // 改寫不會把對話送到回答流程以外的供應商；也可明確指定 providers 中的名稱覆寫
            'provider' => env('RAG_REWRITE_PROVIDER', 'follow'),

            // 改寫結果超過此長度視為失敗（多半是模型開始回答問題）
            'max_chars' => (int) env('RAG_REWRITE_MAX_CHARS', 200),

            // resources/prompts/rag-rewrite-{版本}.md。v1：照抄規範；v2：加上繁體中文、編號原樣保留、完整時原樣輸出、不帶入回答內容；
            // v3：拿掉 v2 的括號例子（Ch13 實測 qwen3 會把例子詞「表單編號」抄進改寫結果）
            'prompt_version' => env('RAG_REWRITE_PROMPT_VERSION', 'v3'),

            // 各 Provider 的改寫設定（model 為 null 時使用 config/llm.php 中該 Provider 的模型）。
            // 沒有登記的 Provider 不改寫：降級為原問題並寫 warning log，不改用另一家
            'providers' => [
                'ollama' => [
                    'model' => env('RAG_REWRITE_OLLAMA_MODEL') ?: null,
                    // Ch13 實測（M1）：qwen3 關閉思考時解不開「第一個問題」這類回指，開啟後 Recall@1 54% → 85%；
                    // 代價是改寫 p50 約 28 秒、p95 約 70 秒，逾時要配合調大
                    'timeout' => (int) env('RAG_REWRITE_OLLAMA_TIMEOUT', 120),
                    'provider_options' => ['think' => (bool) env('RAG_REWRITE_OLLAMA_THINK', true)],
                ],
                'openai' => [
                    'model' => env('RAG_REWRITE_OPENAI_MODEL') ?: null,
                    'timeout' => (int) env('RAG_REWRITE_OPENAI_TIMEOUT', 15),
                    'provider_options' => [],
                ],
                // 測試用（FakeChatProvider）
                'fake' => ['model' => null, 'timeout' => 30, 'provider_options' => []],
            ],
        ],

    ],

    'answer' => [

        // 送進 Context 的最多 Chunk 數。目前與 retrieval.top_k 相同；加入 Reranker 後會先取較多候選，再挑這個數量送給 LLM
        'top_k' => (int) env('RAG_ANSWER_TOP_K', 5),

        // 參考資料的總長度上限（字元數，近似 Token：中文 1 字約 0.7～1 Token）。
        // qwen3 的 num_ctx 為 8192，扣掉 System Prompt、問題與回答的空間後取保守值；超過時從排名最後整段捨去
        'context_budget_chars' => (int) env('RAG_ANSWER_CONTEXT_BUDGET_CHARS', 6000),

        // 沒有候選時的固定回覆，也會帶入 System Prompt，要求 LLM 資料不足時用同一句話回答
        'insufficient_message' => '資料不足',

        // 未指定時使用 config/llm.php 的 chat.default
        'default_provider' => env('RAG_ANSWER_PROVIDER'),

        // 規則與資料分開：System Prompt 只放規則，參考資料放在 user 訊息。
        // 實驗時可用環境變數指定其他版本（resources/ 下的相對路徑），例如 Ch10 比較的 prompts/rag-answer-v1.md
        'system_prompt' => resource_path(env('RAG_ANSWER_SYSTEM_PROMPT', 'prompts/rag-answer.md')),

    ],

    'citation' => [

        // 來源的顯示格式。section 為空時省略條號
        'label_format' => '{document}　{section}　{pages}',

        'page_format' => [
            'single' => '第 {start} 頁',
            'range' => '第 {start}–{end} 頁',
        ],

        // 同檔、同條、同頁的多個編號合併成一行：[3][4] 員工差勤管理規章.pdf　第二章 請假 / 第四條　第 2 頁
        'merge_same_source' => true,

        // 從回答中移除不合規的標記（超出範圍、非數字、來源不存在）；false 時只記錄、不改寫回答
        'strip_invalid' => true,

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
