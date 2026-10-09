# Ch13 第五次回報：鍵名統一與 .env.example 核對

- 日期：2026-10-09
- 測試：616 個全部通過；`pint --test --dirty` 通過。修改的檔案已 `git add`。

## 1. RAG_HISTORY_BUDGET_CHARS → RAG_CONVERSATION_HISTORY_BUDGET_CHARS

全 repo（排除 vendor、.git）搜尋舊鍵名，只有 3 處，已全部修改：

| 檔案 | 位置 |
| --- | --- |
| `config/rag.php` | 第 93 行 `history_budget_chars` |
| `phpunit.xml` | 第 44 行 |
| `docs/notes/ch13-report-4.md` | 第 110 行（`.env` 範例） |

- `ch13-summary.md` 與 CLAUDE.md 只寫設定名稱 `history_budget_chars`，沒有出現環境變數名稱，不需要修改。
- 沒有保留舊鍵名的相容寫法。
- 修改後再搜尋一次，舊鍵名已經沒有出現。
- `php artisan tinker` 確認 `rag.conversation.history_budget_chars` 為 1500。

## 2. 修正：模型鍵留空時被當成模型名稱送出

核對 4 個漏列的鍵時發現一個 bug：

- `RAG_REWRITE_OLLAMA_MODEL=`（留空）時，`env()` 回傳空字串，不是 null。
- 第四次回報改成「各 Provider 分開設定」時，我拿掉了原本的 `?: null`，空字串會直接當成 `ChatOptions::$model`。
- `OllamaProvider` 的 `$options->model ?? 設定值` 擋不住空字串，結果是改寫請求帶著空的模型名稱，每次都失敗、降級。

處理：

- `config/rag.php` 兩個模型鍵改為 `env(...) ?: null`。
- 新增測試 `test_blank_rewrite_model_uses_the_provider_default_model`：phpunit.xml 中該鍵為空字串時，改寫的 `ChatOptions::$model` 應為 null。
- 這個測試先失敗（`Failed asserting that '' is null.`），修正後通過。
- 之前沒有被發現的原因：測試用的 Fake Provider 不看模型名稱；實際評估時 `.env` 沒有這個鍵，所以 `env()` 回傳 null。

## 3. 漏列的 4 個鍵（可直接貼進 .env.example）

鍵名與預設值依 `config/rag.php`：

```dotenv
# 多輪對話總開關；false 時忽略 conversation_id 與歷史，行為與 Ch12 的單輪問答相同（預設 true）
RAG_CONVERSATION_ENABLED=true
# 改寫結果超過此字數視為失敗（多半是模型開始回答問題），降級為原問題（預設 200）
RAG_REWRITE_MAX_CHARS=200
# Ollama 改寫用的模型；留空時使用 OLLAMA_CHAT_MODEL（與回答相同的模型）
RAG_REWRITE_OLLAMA_MODEL=
# OpenAI 改寫用的模型；留空時使用 OPENAI_MODEL（與回答相同的模型）
RAG_REWRITE_OPENAI_MODEL=
```

## 4. config 讀取的 RAG_ 鍵

`grep -no "env('RAG_[A-Z_]*'" config/rag.php | sort -u` 的輸出（`sort` 以「行號:」文字排序，所以順序不是依行號）：

```
101:env('RAG_REWRITE_MAX_CHARS'
105:env('RAG_REWRITE_PROMPT_VERSION'
11:env('RAG_CHUNK_STRATEGY'
111:env('RAG_REWRITE_OLLAMA_MODEL'
114:env('RAG_REWRITE_OLLAMA_TIMEOUT'
115:env('RAG_REWRITE_OLLAMA_THINK'
118:env('RAG_REWRITE_OPENAI_MODEL'
119:env('RAG_REWRITE_OPENAI_TIMEOUT'
132:env('RAG_ANSWER_TOP_K'
136:env('RAG_ANSWER_CONTEXT_BUDGET_CHARS'
14:env('RAG_CHUNK_MAX_TOKENS'
142:env('RAG_ANSWER_PROVIDER'
146:env('RAG_ANSWER_SYSTEM_PROMPT'
17:env('RAG_CHUNK_OVERLAP_RATIO'
174:env('RAG_COLLECTION_PREFIX'
20:env('RAG_CHUNK_MIN_TOKENS'
34:env('RAG_TOP_K'
37:env('RAG_RETRIEVAL_MODE'
40:env('RAG_KEYWORD_ONLY_POLICY'
43:env('RAG_DENSE_CANDIDATES'
44:env('RAG_KEYWORD_CANDIDATES'
47:env('RAG_RRF_K'
66:env('RAG_RERANK_ENABLED'
69:env('RAG_RERANK_CANDIDATES'
72:env('RAG_RERANK_KEEP_EXACT'
75:env('RAG_RERANK_PREFIX_METADATA'
78:env('RAG_RERANK_MIN_SCORE'
86:env('RAG_CONVERSATION_ENABLED'
89:env('RAG_CONVERSATION_WINDOW_TURNS'
93:env('RAG_CONVERSATION_HISTORY_BUDGET_CHARS'
98:env('RAG_REWRITE_PROVIDER'
```

**注意**：你的 `.env` 片段中有 4 個 Reranker 鍵不在 `config/rag.php`，而是在 `config/llm.php`（連線設定），上面的指令列不出來：

```
34:env('RAG_RERANK_PROVIDER'
44:env('RAG_RERANK_BASE_URL'
45:env('RAG_RERANK_MODEL'
47:env('RAG_RERANK_TIMEOUT'
```

核對 `.env.example` 時，請同時用 `grep -no "env('RAG_[A-Z_]*'" config/llm.php | sort -u`。

**Ch13 的 13 個鍵**（`RAG_CONVERSATION_*` 3 個、`RAG_REWRITE_*` 10 個）：

- 你的片段已列出 9 個；加上第 3 節的 4 個，與 `config/rag.php` 完全一致。
- 片段中的 `RAG_CONVERSATION_HISTORY_BUDGET_CHARS` 現在與程式一致。

## 其他留空的鍵

`RAG_REWRITE_PROVIDER`、`RAG_REWRITE_PROMPT_VERSION` 也是讀字串。如果在 `.env` 寫成空值（`RAG_REWRITE_PROVIDER=`），不會套用預設值：

- `RAG_REWRITE_PROVIDER` 留空：找不到改寫設定，每次都降級（`no_rewrite_config`）。
- `RAG_REWRITE_PROMPT_VERSION` 留空：讀取 `rag-rewrite-.md` 失敗。

目前兩個都有填值，沒有影響。要不要也加上 `?: 預設值`，由你決定。
