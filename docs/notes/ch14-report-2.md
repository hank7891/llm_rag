# Ch14 第二次回報：第一個停止點（介面與測試完成，尚未驗收）

- 日期：2026-10-09
- 測試：640 個全部通過（Ch13 結束時 616 個，新增 24 個）；`pint --test --dirty` 通過；`npm run build` 成功。
- 回歸基準：已先在 `ch13-done` 上用最終線上設定跑完（`docs/notes/ch14-baseline/`），第二階段會拿來逐題比對。
  - 線上設定：Reranker 開啟、候選 20、keep_exact、prefix_metadata；改寫 follow → ollama v3 think；Window 5。
  - 多輪評估在這組設定下 Recall@1 / @5 為 100% / 100%（Reranker 關閉時是 85% / 100%）。

## A. 新增與修改的檔案

### 新增

| 檔案 | 責任 |
| --- | --- |
| `app/Rag/Answer/RagProgressListener.php` | 進度回呼介面：conversation、stage、rewrite、retrieval、delta、cancelled |
| `app/Rag/Answer/RagStage.php` | 處理階段 enum：rewriting / retrieving / generating |
| `app/Http/Streaming/SseRagProgressListener.php` | 把進度轉成 SSE 事件；寫出與斷線偵測由外部傳入，方便測試 |
| `app/Http/Controllers/Api/KnowledgeAskStreamController.php` | `POST /api/knowledge/ask/stream`：SSE 標頭、執行時間上限、`ignore_user_abort`、最後送出 done，錯誤只送類型與固定訊息 |
| `app/Http/Controllers/KnowledgeChatController.php` | `/knowledge/chat` 頁面；Provider 清單只列出有改寫設定的 |
| `app/Ai/Chat/ChatProviderCatalog.php` | 介面用的 Provider 清單（名稱、是否地端、模型），由 AiServiceProvider 從 `config/llm.php` 組裝 |
| `app/Rag/Stats/QueryLogStats.php` | 統計：回答狀態、改寫 fallback 率、Rerank 降級率、各階段 p50 / p95 |
| `app/Console/Commands/QueryStatsCommand.php` | `rag:stats {--since=} {--until=} {--include-eval}` |
| `resources/views/knowledge/chat.blade.php` | 問答頁的版面 |
| `resources/js/knowledge-chat.js` | 問答頁：串流讀取（fetch + ReadableStream）、階段進度、等待秒數、done 取代暫時文字、來源標記、停止、新對話；只用 `textContent` |
| `resources/js/documents.js` | 列表頁：刪除確認（從 data 屬性讀取）、處理中文件的狀態輪詢 |
| `database/migrations/2026_10_09_000004_add_provider_to_conversations_table.php` | `conversations.provider`：建立對話時的回答 Provider |
| `database/migrations/2026_10_09_000005_add_rerank_columns_to_rag_query_logs_table.php` | `rag_query_logs.reranked`、`rerank_degraded`、`rerank_ms` |
| `tests/Feature/Rag/KnowledgeStreamTest.php` | 串流協定、資料不足短路、與非串流一致、引用清理、中斷、錯誤不洩漏、Provider 鎖定、頁面清單、靜態 XSS 檢查（11 個） |
| `tests/Feature/Rag/QueryStatsTest.php` | 以固定紀錄驗證比例與 p50 / p95，以及預設排除評估提問（5 個） |

### 修改

| 檔案 | 修改內容 |
| --- | --- |
| `app/Rag/Answer/RagAnswerService.php` | 加入可選的 Listener；有 Listener 時回答改用 `ChatService::stream()`；中斷處理；Provider 在建立對話時記下 |
| `app/Rag/Answer/AnswerStatus.php` | 新增 `interrupted`；`isInsufficient()` 改成明確列出兩種資料不足（原本是「不是 answered 就算不足」，會把 interrupted 誤判為資料不足） |
| `app/Repositories/ConversationRepository.php` | `create($provider)`、`find()`；`recentTurns()` 由新到舊逐筆讀取，略過 interrupted 的輪次 |
| `app/Repositories/RagQueryLogRepository.php`、`app/Models/RagQueryLog.php` | 寫入 Reranker 欄位；新增 `between()` 給統計使用 |
| `app/Http/Requests/AskKnowledgeRequest.php` | 同一段對話帶了不同的 Provider 時回 422 |
| `app/Http/AiErrors.php` | 新增 `publicBody()`：錯誤類型加固定中文訊息（仍是唯一的例外對照表） |
| `app/Rag/Conversation/QueryRewriter.php` | 新增 `supports($provider)` |
| `app/Documents/DocumentStatus.php`、`DocumentService.php`、`DocumentRepository.php`、`DocumentController.php` | `isProcessing()`、`canReindex()`；重新索引；狀態查詢端點；Chunk 數；上傳重複內容時列出既有文件名稱 |
| `resources/views/documents/index.blade.php`、`_status.blade.php`、`layouts/app.blade.php` | 移除整頁 meta refresh；加上 Chunk 欄、重新索引、輪詢標記；導覽加上「知識問答」；**修正刪除確認的 XSS**（見 E 節） |
| `config/llm.php` | `chat.labels`：顯示名稱與是否地端 |
| `config/rag.php` | `answer.stream_time_limit`（`RAG_STREAM_TIME_LIMIT`，預設 600 秒） |
| `routes/api.php`、`routes/web.php` | 串流端點、`/knowledge/chat`、`/document/statuses`、`/document/{id}/reindex` |
| `phpunit.xml` | 固定 `RAG_STREAM_TIME_LIMIT` |
| `vite.config.js` | 加入兩個 JS 進入點 |
| `tests/Feature/Documents/DocumentPagesTest.php` | 以輪詢標記取代 meta refresh 測試；新增狀態端點、重新索引、XSS、重複內容提示的測試 |
| `tests/Feature/Rag/ConversationTest.php` | 測試對話改成從頭使用同一個 Provider（配合新的 Provider 鎖定規則） |

## B. RagAnswerService 重構前後

**重構前**：`answer()` → `conversation()` → `generate()`；回答固定使用 `ChatService::chat()`。

**重構後**（同一個流程，沒有複製）：

```
answer($question, $options, ?RagProgressListener $listener = null)
  conversation()：決定對話、歷史與 Provider（新對話記下 Provider；既有對話沿用，帶了不同的 Provider 就拒絕）
  $listener?->conversation(id)
  generate()：
    stage(rewriting) → 改寫 → rewrite(result)
    stage(retrieving) → 檢索 → retrieval(result)
    沒有候選 → 直接回傳資料不足（不呼叫 ChatService）
    stage(generating) → $listener === null ? chat() : streamed()
       streamed()：逐段 delta()；最後一段（帶 usage）組回與 chat() 相同的 ChatResult；
                   cancelled() 為 true 時停止讀取，回傳部分文字 → 狀態 interrupted
    之後的資料不足判斷、Citation、來源產生完全共用
  保存對話、寫入 rag_query_logs
```

**如何確認非串流行為不變**：

1. 重構後立刻跑既有的 616 個測試，全部通過，沒有修改任何既有斷言。唯一的例外是對話測試改成從頭使用同一個 Provider，配合新的 Provider 鎖定規則。
2. 新增 `test_stream_done_matches_non_stream_answer`：同一題的串流 done 與非串流回應，以下欄位逐一相同：answer、status、llm_called、citations、sources、references、warnings、rewrite_status、provider、model、usage。
3. 第二階段會以回歸基準逐題比對檢索與多輪評估。

**行為改變（一項，經你同意的決策 7）**：接續對話時沒帶 provider，現在沿用建立對話時的 Provider，不再使用預設值；帶了不同的 Provider 會回 422。Ch13 建立的舊對話沒有記錄 Provider，不受限制。

**反向驗證**（暫時拿掉關鍵程式，確認測試會失敗，之後都已還原）：

| 拿掉的程式 | 結果 |
| --- | --- |
| 資料不足短路 | `test_no_candidates_sends_done_without_generation_or_llm_call` 失敗 |
| 歷史排除 interrupted | `test_interrupted_answer_stops_reading_and_is_excluded_from_history` 失敗 |
| 串流中檢查中斷 | 同上失敗 |
| 錯誤事件改送原始例外訊息 | `test_error_event_does_not_leak_exception_details` 失敗 |
| Provider 鎖定 | `test_switching_provider_within_a_conversation_is_rejected` 失敗 |

## C. 串流事件實際輸出

以下是用 `curl` 對本機實際呼叫的結果：ollama、Reranker 開啟、改寫 follow。

### 資料不足（「公司有提供員工宿舍嗎？」）：0.4 秒結束，沒有 generating 與 delta

```
event: conversation
data: {"conversation_id":6}

event: stage
data: {"stage":"rewriting"}

event: rewrite
data: {"rewritten_question":"公司有提供員工宿舍嗎？","rewrite_status":"skipped"}

event: stage
data: {"stage":"retrieving"}

event: retrieval
data: {"candidates":0,"has_candidates":false,"reranked":false,"rerank_degraded":false}

event: done
data: {"answer":"資料不足","status":"insufficient_no_candidates",…,"llm_called":false,"references":[],…}
```

### 正常回答（「公司的特休規定是什麼？」）：第一段文字 14 秒、完成 26 秒，共 219 個 delta

```
event: conversation
data: {"conversation_id":8}

event: stage
data: {"stage":"rewriting"}

event: rewrite
data: {"rewritten_question":"公司的特休規定是什麼？","rewrite_status":"skipped"}

event: stage
data: {"stage":"retrieving"}

event: retrieval
data: {"candidates":5,"has_candidates":true,"reranked":true,"rerank_degraded":false}

event: stage
data: {"stage":"generating"}

event: delta
data: {"text":"公司的"}
…（共 219 個 delta）…

event: done
data: {"answer":"公司的特別休假規定如下：…服務滿二年以上未滿三年者，給予十日 [1]。…","status":"answered",
       "citations":[{"ref":1,"label":"員工管理辦法.pdf　第十二條　第 2 頁",…},…],"llm_called":true,…}
```

### 中斷（`curl -m 16`，收到 30 個 delta 後切斷）

- 伺服器停止讀取 LLM 串流。
- 助理訊息存成 `interrupted`，只保留已收到的 50 字。
- `rag_query_logs` 記為 interrupted：`llm_ms=14153`、`reranked=1`、`rerank_ms=1763`。

### 問答進行中載入其他頁面（4 個 worker，見 D 節）

| 頁面 | 結果 |
| --- | --- |
| `/document` | HTTP 200，0.07 秒 |
| `/knowledge/chat` | HTTP 200，0.02 秒 |

## D. 本機啟動方式

```bash
# 終端機 1：開發伺服器。必須同時設定 PHP_CLI_SERVER_WORKERS 與 --no-reload（見 E 節第 1 點）
#   目前在 port 8000 的 php artisan serve 是單一 worker，請先停止再重開
PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload

# 終端機 2：背景 Job（上傳、解析、切段、索引）
php artisan queue:listen

# Reranker：llama-server 已由你啟動（port 8012）
# 前端資源：已執行 npm run build；之後修改 JS 時再執行一次，或改用 npm run dev
```

瀏覽器打開：

- `http://127.0.0.1:8000/knowledge/chat`：問答。可勾選或取消「串流顯示」，比較兩種等待感受。
- `http://127.0.0.1:8000/document`：列表、上傳、刪除、重新索引。

`--no-reload` 代表 `.env` 修改後要手動重啟伺服器。

建議試的流程：

1. 用 Ollama 問「公司的特休規定是什麼？」，再問「那兩年年資呢？」。第二題開了 think，改寫要等 20～80 秒，可以觀察階段進度與等待秒數。
2. 問「公司有提供員工宿舍嗎？」，應該直接顯示資料不足，沒有「產生回答」的過程。
3. 按「新對話」，換成 OpenAI 問同一題。畫面上方的提示會變成雲端（黃色）。
4. 回答途中按「停止」，再追問一題，確認被停止的那一輪不會進入歷史。
5. 問答進行中，另開分頁打開 `/document`，確認可以正常載入。

## E. 實作時發現的問題

### 1. `PHP_CLI_SERVER_WORKERS` 要搭配 `--no-reload` 才有效

- 第一次實測時，`/document` 在串流期間等了 18.4 秒，正好等到串流結束才回應。
- Laravel 的 `serve` 在沒有 `--no-reload` 時，只會顯示警告「Unable to respect the PHP_CLI_SERVER_WORKERS … without the --no-reload flag」，然後開單一 worker。
- 加上 `--no-reload` 後是 0.07 秒。
- CLAUDE.md 常用指令的寫法（只設 `PHP_CLI_SERVER_WORKERS=4`）是無效的，第二階段更新 CLAUDE.md 時一併修正。

### 2. 已修正：刪除確認的 XSS（Ch03 起就存在）

- 原本寫成 `onsubmit="return confirm('…{{ $document->name }}…')"`。
- Blade 會把 `'` 轉成 `&#039;`，但瀏覽器解析屬性時會先還原成 `'` 才執行 JS。所以檔名如果是 `a');alert(1);//.pdf`，就能執行任意程式。
- 改成 `data-confirm` 屬性，由 JS 以純文字讀取。
- 加上測試：頁面中不再出現 `onsubmit`，且檔名在 data 屬性中已正確跳脫。

### 3. 需要你決定：CORS 允許所有來源

- **現況**：
  - 專案沒有 `config/cors.php`，使用框架預設：`api/*` 允許所有來源（`Access-Control-Allow-Origin: *`）。
  - API 沒有身分驗證，所以任何網站都能從內部同仁的瀏覽器呼叫 `http://127.0.0.1:8000/api/knowledge/ask`，並**讀到回答內容**。
  - 也就是說，一個外部網頁可以借用同仁的瀏覽器，查詢內部知識庫並把結果送出去。
  - 這和第一次回報決策 2 的前提有關：我當時說「沒有 Cookie 驗證，所以 CSRF 沒有東西可保護」。這點仍然成立，但我漏看了 CORS 會讓跨站的請求**讀得到回應**。
- **建議**：
  - 新增 `config/cors.php`，`allowed_origins` 只允許 `APP_URL`（同源的頁面本來就不需要 CORS）。
  - 這是安全規則的修改，請你確認後我再做。
  - 也可以列入 backlog，等加入登入時一起處理。

### 4. 其他

- `/document` 的輪詢改成每 3 秒只查詢處理中文件的狀態（`/document/statuses`），狀態改變才重新載入頁面。原生的 `confirm()` 視窗開著時，計時器會暫停，所以不會打斷刪除確認。
- 輪詢的狀態包含 parsed、chunked。這兩個是 Job 之間的狀態，原本整頁重新整理時會漏掉。
- 串流期間 `ChatService` 照常記錄用量：最後一段帶 usage 時才記錄，所以中斷的請求沒有用量紀錄。這是 Ch02 既有的行為。

## 需要你加入 `.env.example`（與 `.env`）的設定

```dotenv
# --- 串流問答與開發伺服器（Ch14）---
# 串流問答的執行時間上限（秒）；必須大於「改寫逾時 + 回答串流期限」（Ollama：120 + 300）
RAG_STREAM_TIME_LIMIT=600
# php artisan serve 的 worker 數；一條 SSE 串流會佔住一個 worker，單一 worker 時問答期間其他頁面都會卡住
# 必須搭配 --no-reload：PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload（.env 修改後要手動重啟）
PHP_CLI_SERVER_WORKERS=4
```

**注意**：`PHP_CLI_SERVER_WORKERS` 是由 `serve` 指令讀取的環境變數，寫在 `.env` 也有效。但仍然要加 `--no-reload`，否則一樣只有單一 worker。

## 第二階段（等你操作過畫面再開始）

1. 回歸比對：寫入 `docs/notes/ch14-regression.md`。
2. 最終驗收報告：`docs/notes/ch14-acceptance.md`。標「你」的項目會留下截圖欄位。
3. `rag:stats` 的結果。
4. `docs/notes/ch14-summary.md` 與 `docs/notes/final-summary.md`。
5. 更新 CLAUDE.md：
   - 串流與非串流共用 Pipeline。
   - 模型輸出不得以 HTML 插入。
   - `PHP_CLI_SERVER_WORKERS` 要搭配 `--no-reload`。
