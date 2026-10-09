# .env 區段（Ch13 最終版）

把下方程式碼區塊內的全部內容（不含 ``` 那兩行）整段貼到 `.env` 與 `.env.example`。

貼之前先刪除舊鍵（程式已不再讀取）：`RAG_HISTORY_BUDGET_CHARS`、`RAG_REWRITE_MODEL`、`RAG_REWRITE_TIMEOUT`。

```dotenv
# --- 文件解析：poppler 的 pdftotext 執行檔（brew install poppler）---
# 填完整路徑：Queue Worker 等背景程式不一定有 /opt/homebrew/bin 的 PATH
# 移進 Docker（Linux）後改為 /usr/bin/pdftotext
PDFTOTEXT_PATH=/opt/homebrew/bin/pdftotext

# --- Reranker（llama.cpp llama-server，原生執行）---
# 啟動：llama-server -m ~/models/bge-reranker-v2-m3-Q8_0.gguf --alias bge-reranker-v2-m3 --embedding --pooling rank --reranking --host 127.0.0.1 --port 8012 -c 8192 -b 2048 -ub 2048
# 要用就先啟動 llama-server 再設 true；不用就關閉服務並設 false
# true 但服務未啟動時，每次提問都會降級並寫入 rerank.degraded warning
# false 時檢索結果與 Ch11 完全相同；上線建議值見 ch12-summary
RAG_RERANK_ENABLED=false
RAG_RERANK_PROVIDER=llamacpp
# Laravel 移進 Docker 時改用 http://host.docker.internal:8012
RAG_RERANK_BASE_URL=http://127.0.0.1:8012
# 與 llama-server 的 --alias 一致
RAG_RERANK_MODEL=bge-reranker-v2-m3
# 送進 Reranker 的候選數；越多越準但越慢（每次提問都要逐筆重算）
# 20：候選上限只影響超過 10 段的題目，文件變多後才看得出效果（見 ch12-summary）
RAG_RERANK_CANDIDATES=20
# 秒；逾時或失敗時退回原本的排序，問答不中斷
# 設太短會靜默降級，比多等幾秒更難發現
RAG_RERANK_TIMEOUT=6
# true：精確命中（編號、條號）的段落保證保留在最終結果，不會被 Reranker 擠掉
RAG_RERANK_KEEP_EXACT=true
# true：送進 Reranker 的段落前面加上「文件名稱 條號：」
RAG_RERANK_PREFIX_METADATA=true

# --- 多輪對話：Conversation Memory 與 Query Rewriting（Ch13）---
# 鍵名打錯不會報錯，會靜默改用 config/rag.php 的預設值
# 多輪對話總開關；false 時忽略 conversation_id 與歷史，行為與 Ch12 的單輪問答相同（預設 true）
RAG_CONVERSATION_ENABLED=true
# Sliding Window：保留最近幾輪（一問一答為一輪）
RAG_CONVERSATION_WINDOW_TURNS=5
# 歷史總長度上限（字元）；超過時從最舊的一輪捨去
# 長回答時可能比 WINDOW_TURNS 先截斷：實際保留輪數見 log conversation.history 的 kept（API 回應為 history_turns）
RAG_CONVERSATION_HISTORY_BUDGET_CHARS=1500
# 改寫 Provider：follow = 跟隨本次實際的回答 Provider（含 API 與 --provider 逐次指定）
# 也可明確指定 ollama 或 openai 覆寫；沒有改寫設定的 Provider（例如 gemini）會降級為原問題，不會改用另一家
RAG_REWRITE_PROVIDER=follow
# 改寫 Prompt 版本（resources/prompts/）；修改後必須重跑 rag:eval-conversation
RAG_REWRITE_PROMPT_VERSION=v3
# 改寫結果超過此字數視為失敗（多半是模型開始回答問題），降級為原問題（預設 200）
RAG_REWRITE_MAX_CHARS=200
# Ollama 改寫用的模型；留空時使用 OLLAMA_CHAT_MODEL（與回答相同的模型）
RAG_REWRITE_OLLAMA_MODEL=
# Ollama 改寫：關閉思考時 qwen3:8b 解不開回指，因此開啟；代價是改寫 p50 約 29 秒、p95 約 77 秒
RAG_REWRITE_OLLAMA_THINK=true
# 秒；開啟思考後設 30 秒會讓約一半的題目降級（16 題中有 8 題超過 30 秒）
RAG_REWRITE_OLLAMA_TIMEOUT=120
# OpenAI 改寫用的模型；留空時使用 OPENAI_MODEL（與回答相同的模型）
RAG_REWRITE_OPENAI_MODEL=
# 秒；OpenAI 改寫 p50 約 1.0 秒、p95 約 4.5 秒（v3、N=5）
RAG_REWRITE_OPENAI_TIMEOUT=15
```
