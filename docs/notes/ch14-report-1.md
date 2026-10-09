# Ch14 第一次回報：範圍確認（尚未動手）

- 日期：2026-10-09
- 依據：
  - `docs/txt/Ch14 UI 與最終驗收：學習大綱與交接文件.md`
  - 教學文件第十六章（第一版 UI）、第二十章（最終驗收）
  - 程式現況盤點
- 教學文件第二十章的 10 項驗收，和交接文件 5.5 的 10 項一一對應，沒有出入。

## 一、前置作業現況

| 項目 | 現況 |
| --- | --- |
| `ch13-done` 已 commit、tag 存在 | ✔（46f6543） |
| `git status` 乾淨 | △ 只剩 `AGENTS.md`、`CLAUDE.md`、`docs/` 未追蹤，這三者一直沒有進版控（見第三節第 9 點） |
| hook 改為 `$CLAUDE_PROJECT_DIR` 絕對路徑 | **✘ 尚未修改**：`.claude/settings.json` 仍是 `bash .claude/hooks/protect_sensitive.sh` |
| `scripts/check-env.sh` | ✔ 全部通過 |
| llama-server | ✔ 有回應（但它是我這個工作階段的背景程序，工作階段結束時會一起停止） |
| `.env` 的 `RAG_RERANK_ENABLED` | 目前是 **false**；交接文件寫「目前為 true」，與現況不符。最終驗收要用線上設定，屆時要改成 true |
| OpenAI 與 Gemini API Key | ✔ 都有設定 |
| 驗收用新文件 | 由你準備（第 1 項會送到 OpenAI，請選可以外送的內容，或自行編寫假規章） |

## 二、現況盤點：哪些已經有了

交接文件寫「三個頁面」是新增的，但 **`/document` 與 `/document/upload` 在 Ch03 已經做好**，本章是擴充，不是從零開始。

| 能力 | 現況 | 本章要補的 |
| --- | --- | --- |
| `/document` 列表 | 檔名、狀態、頁數、大小、上傳時間、`error_message`；刪除（Ch07 同步刪除 Qdrant）、重新處理；處理中時整頁 `<meta refresh>` 每 3 秒重新整理 | Chunk 數；「重新索引」；輪詢方式（見決策 4） |
| `/document/upload` | 上傳表單、大小上限、MIME 驗證（`UploadDocumentRequest`） | sha256 重複時顯示既有文件名稱（目前只在列表標示重複，見決策 5） |
| `/document/{id}` | 逐頁文字、Chunk 檢視 | 不需要改 |
| `/knowledge/chat` | **沒有** | 全新頁面 |
| 串流 | Ch02 有 `POST /api/ai/chat/stream`（`response()->eventStream()`，先取第一段再送 header）、`ChatService::stream()`、各 Provider 與 Fake 都支援 stream | RAG 串流端點與事件協定 |
| 前端 | Blade + Vite + Tailwind，`resources/js/app.js` 幾乎是空的 | 原生 JS（fetch + ReadableStream） |

## 三、與交接文件的出入與需要你決定的事

### 1. 分支

- **交接文件**：5.7 要求建立分支 `ch14`。
- **出入**：我們一直的規則是直接在 main 上工作、不開分支。
- **建議**：沿用 main，不開分支。

### 2. 串流端點放在 API 路由還是 Web 路由（安全規則，請你裁斷）

交接文件同時要求兩件事：

- 端點為 `POST /api/knowledge/ask/stream`。
- 「Web 路由啟用 CSRF」與「開始串流前釋放 Session」。決策 2 也寫「POST 也能沿用 CSRF」。

但 `routes/api.php` 沒有 Session 與 CSRF 中介層，兩者無法同時成立。

| 做法 | 優點 | 缺點 |
| --- | --- | --- |
| **(a) 放在 `/api`，無狀態（建議）** | 和既有的 `/api/knowledge/ask` 一致；沒有 Session，也就沒有 Session 鎖的問題 | 沒有 CSRF。但 API 目前沒有任何以 Cookie 為基礎的身分驗證，CSRF 能保護的東西本來就不存在（CSRF 是利用瀏覽器自動帶 Cookie） |
| (b) 放在 Web 路由，例如 `POST /knowledge/chat/stream`，啟用 CSRF | 符合字面要求 | 每個請求都會啟動 Session，串流前要手動釋放 Session 鎖，多一個陷阱 |

建議 (a)。上傳、刪除、重新處理這些表單維持在 Web 路由並使用 CSRF（現況已經如此）。日後加入登入時，再把串流端點移到有 Session 的路由。

### 3. 中斷的訊息存在哪裡（資料契約，請你裁斷）

- **交接文件**：中斷時助理訊息 `status = interrupted`。
- **現況**：`conversation_messages.status_key` 存的是 `AnswerStatus`（answered / insufficient_*）。
- **建議**：在 `AnswerStatus` 新增 `interrupted`。
  - 它也是「這次回答的結果」，符合「一個欄位一組常數」的規則。
  - 中斷時存下使用者問題與已產生的半截回答，狀態為 interrupted。
  - `ConversationRepository::recentTurns()` 略過 interrupted 的那一輪。
- **另一種做法**：中斷時整輪不存。最簡單，但查不到「使用者中途停止」的紀錄。
- **限制（未實測）**：
  - PHP 要等寫出資料時才偵測得到斷線，需要搭配 `ignore_user_abort(true)`，才有機會把訊息標成 interrupted。
  - Ollama 已經在生成的部分無法要回來：停止讀取後，模型可能仍會跑完這一次。
  - 這兩點要在實作時實測。

### 4. `/document` 的輪詢方式

- **現況**：整頁 `<meta refresh>`。
  - 只在處理中重新整理，符合「處理中才輪詢」。
  - 但重新整理時，刪除確認視窗會被打斷。
  - `parsed`、`chunked` 這兩個中間狀態不在清單中，Job 之間的空檔會停止重新整理。
- **建議**：
  - 改成原生 JS 每 3 秒 fetch 一個 JSON 狀態端點，只更新狀態欄。
  - 所有非終止狀態都輪詢：uploaded、parsing、parsed、chunking、chunked、indexing。
  - 全部變成 indexed 或 failed 就停止。

### 5. 「重新索引」與重複檔案

- **重新索引**：
  - 現況只有「重新處理」，從解析開始重跑整條 Pipeline。
  - 交接文件要的「重新索引」相當於 `rag:index`，只重建向量索引。
  - **建議**：保留「重新處理」，另外新增「重新索引」，只對 indexed 的文件顯示，背景執行 `IndexDocumentJob`。
- **sha256 重複**：
  - 現況是允許上傳，並在列表標示「與 #n 內容相同」（Ch03 的決定）。
  - 交接文件寫「重複時顯示既有文件名稱」，沒有說要不要擋下。
  - **建議**：維持允許上傳，在上傳成功訊息中列出既有的同內容文件名稱。如果你要改成擋下，那是改變 Ch03 的規則，請明確告訴我。

### 6. `rag:stats` 需要新增欄位（請你確認）

交接文件寫「`rag_query_logs` 已有欄位」，但實際上**沒有 Reranker 的欄位**：

- 現有欄位：rewrite_status、rewrite_ms、retrieval_ms、llm_ms、status、llm_called、provider、model。
- 沒有：reranked、rerank_degraded、rerank_ms。
- `retrieval_ms` 包含重排時間，無法分開。

**建議**：

- 新增 migration，加上 `reranked`、`rerank_degraded`、`rerank_ms`。只是記錄，不改變任何檢索行為。
- 中斷的問答也寫一筆 log（status = interrupted），統計才完整。

**限制**：

- Ch13 以前的 log 沒有重排紀錄，Rerank 降級率只能從 Ch14 開始統計。
- `laravel.log` 有 `rerank.degraded` 的 warning，但對不到是哪一筆問答。
- 改寫 fallback 比例可以從 Ch13 開始統計。

### 7. Provider 下拉選單（請你確認）

- **列出哪些 Provider**：
  - 設定中有 ollama、openai、gemini 三家（金鑰都有）。
  - 但 gemini 沒有改寫設定，依 Ch13 規則，用 gemini 時追問一律不改寫（降級）。
  - **建議**：只列出有改寫設定的 Provider（ollama、openai），清單直接由 `rag.conversation.rewrite.providers` 推導（排除 fake）。
- **地端或雲端的標示**：
  - 不在 View 或 Controller 用 `if ($provider === 'ollama')` 判斷，否則驗收第 7 項的 grep 會抓到。
  - **建議**：在 `config/llm.php` 為每個 Chat Provider 加上 `label` 與 `local: true/false`。
- **「只在新對話生效」由誰把關**：
  - 交接文件只要求前端鎖住選單。
  - 依「不信任 Client」的原則，建議 Server 端也要把關：`conversations` 新增 `provider` 欄位（需要 migration），建立對話時記下。
  - 後續請求帶了不同的 Provider 時，回 422（建議），或忽略請求值、沿用對話的 Provider。

### 8. 錯誤事件的內容

- **交接文件**：error 事件「不含堆疊與內部路徑」。
- **現況**：Ch02 串流的 error 事件帶的是 `AiErrors::body($e)`。內容雖經 `redact()` 遮蔽 API Key，但上游訊息仍可能包含 Ollama 位址等內部資訊。
- **建議**：RAG 串流的 error 事件只送錯誤類型（timeout、unavailable、rate_limited…）與固定的中文訊息，完整訊息只寫進 log。

### 9. docs/ 與 CLAUDE.md、AGENTS.md 是否進版控（請你裁斷）

- **交接文件 7.1** 寫 `git add docs/notes/`，截圖也放在 `docs/notes/ch14-screenshots/`。
- **CLAUDE.md 的 Git 規範**是「排除 `docs/`」，Ch01～Ch13 的筆記也都沒有進版控。
- `CLAUDE.md`、`AGENTS.md` 本身也一直是 untracked。
- **請你決定**：第一階段收尾時，是否把 `docs/`（或只有 `docs/notes/`）與這兩個檔案納入版控。如果要納入，CLAUDE.md 的 Git 規範要一起修改。

### 10. AGENTS.md 要不要寫規則

- **交接文件 5.7 第 11 項**：「更新 CLAUDE.md 與 AGENTS.md」。
- **AGENTS.md 現況**：明寫「專案規則只有一份，寫在 CLAUDE.md，本檔不重複規則內容」。
- **建議**：規則只寫進 CLAUDE.md，AGENTS.md 不動。

### 11. 回歸的基準（建議調整做法）

交接文件要求「以 Ch13 的線上設定重跑，和 Ch13 結果比對」，但 **Ch13 沒有線上設定（Reranker 開啟）的結果可比**：

- questions、hybrid、reasoning 的 Ch13 回歸都是 Reranker 關閉。
- Ch13 的 `rag:eval-conversation` 也都是 `--rerank=off`。
- 目前唯一有 Reranker 開啟、think 關閉、v2 的 C 組，設定和最終版不同。

**建議**：

- 動手前，先在 `ch13-done` 上用最終線上設定跑一次全部評估，作為本章的基準：
  - Reranker 開啟、候選 20、keep_exact、prefix_metadata。
  - 改寫 follow（ollama、v3、think）、Window 5。
- 本章結束時用相同設定重跑，逐題比對。
- 檢索結果必須完全相同。改寫含 think 的生成可能有小差異，若有就逐題列出。
- 基準預估約 15 分鐘（多輪評估每題改寫約 30 秒）。

### 12. 其他小出入（照現況處理，不需要決定）

- **驗收第 9 項**：教學文件以 `AnthropicProvider` 為例，但本專案沒有這個類別。只支援 Chat 的是 `OpenAIProvider`、`GeminiProvider`（只實作 `ChatProviderInterface`），`OllamaProvider` 兩者都實作。證據改用這兩個類別的宣告，加上既有的 `UnknownEmbeddingProviderException` 測試。
- **驗收第 8 項**：Qdrant 有兩個 Collection，`company_docs_bge_m3` 77 點、`company_docs_qwen3_embedding_0_6b` 72 點。點數不同，代表 qwen3 的索引是 Ch08 之後沒有重建的舊資料。驗收時附上 `rag:index-check --model=` 的結果並說明，不重建（重建屬於調整 Embedding 索引，超出本章範圍）。
- **文件狀態**：交接文件列的處理中狀態（uploaded / parsing / parsed / indexing）少了 Ch05 加入的 chunking、chunked，依實際的 `DocumentStatus` 處理。
- **執行時間上限**：本機 PHP 的 `max_execution_time` 是 0（不限）。串流端點仍會明確設定上限，大於「改寫逾時 + 回答逾時」：Ollama 為 120 + 300 秒。
- **`PHP_CLI_SERVER_WORKERS`**：`.env.example` 受 hook 保護，屆時我列出內容，由你加入。
- **回答格式**：照決策 4 用純文字，保留換行；Markdown 列入 backlog。
- **前端技術、讀取方式**：照決策 1、2，使用 Blade + Vite + 原生 JS，以 fetch + ReadableStream 讀取 POST 串流。

## 四、本章重點（實作前先理解的事）

1. **串流與非串流共用同一個 Pipeline**：
   - `RagAnswerService` 抽出階段流程，加上可選的 `RagProgressListener`。
   - 非串流傳空的 Listener，輸出必須和 Ch13 完全相同。
   - 有 Listener 時，回答階段改用 `ChatService::stream()`，最後組出的 `RagAnswer` 欄位與規則完全相同。
2. **`done` 是唯一的真相**：
   - delta 只是暫時顯示，收到 done 後，以清理過的 answer 取代畫面文字。
   - 來源只從 done 的 references／citations 產生。
   - 這是因為 Ch10 的引用檢查要等全文才能做。
3. **資料不足短路**：沒有候選時只送 conversation、stage、rewrite、retrieval、done，不呼叫 ChatService。這是驗收第 3 項的畫面證據。
4. **感知延遲**：地端追問一次約 40～110 秒。分階段顯示「理解問題 → 搜尋文件 → 產生回答」與已等待秒數，總時間不變，但使用者知道系統在動。
5. **SSE 的靜默失敗**：
   - 輸出緩衝。
   - `php artisan serve` 的單一 worker 被串流佔住，`PHP_CLI_SERVER_WORKERS`（建議 4）可以解決。
   - 執行時間上限、Session 鎖、客戶端中斷。
   - 這些都不會報錯，只會「看起來怪怪的」。
6. **XSS**：模型輸出、文件名稱、改寫問題一律用 `textContent` 插入。驗收時上傳含 `<script>` 的文件，確認不會執行。
7. **降級要能統計**：改寫 fallback 與 Rerank 降級都是靜默的，`rag:stats` 把比例和各階段 p50／p95 算出來。

## 五、預計的實作順序（確認後開始）

1. 先跑回歸基準（第三節第 11 點）。
2. `RagAnswerService` 重構與 `RagProgressListener`：先證明非串流行為不變。
3. `rag_query_logs` 的 Reranker 欄位（以及決策 7 的 `conversations.provider`）。
4. 先做非串流版的 `/knowledge/chat`，確認畫面與資料正確。
5. 串流端點與事件協定，前端改成串流，加上中斷處理。
6. `/document` 輪詢、重新索引、Chunk 數，以及上傳的重複提示。
7. `rag:stats`。
8. 測試（5.4 全部項目），然後停下來回報（第一個停止點），等你操作畫面。
