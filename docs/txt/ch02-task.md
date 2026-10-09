# Ch02 任務：Ollama 與雲端 Provider 實作

> 對應教學文件：第三階段「LLM API 串接」後半（各家 API 格式差異、Streaming、驗收方式）
> 本章依 Ch01 定義的 DTO 與 `ChatProviderInterface`，接上真實模型。

## 本章範圍

**要做**：`OllamaProvider`、`AnthropicProvider`、`POST /api/ai/chat`（一般與 SSE 串流）、錯誤分類、Token 用量記錄、測試、手動驗證方式。

**不要做**：
- 文件上傳、Embedding、Vector DB、RAG
- 自動重試、對話歷史截斷（屬於 Ch13）
- OpenAI / Gemini Provider
- commit

## 設計決定（請遵守，有疑慮請在回報中提出，不要自行更改）

1. **雲端 Provider 採用 Anthropic Messages API**。選它是因為格式差異最明顯：system 放在頂層參數、`max_tokens` 必填、串流事件類型多，最能檢驗抽象層。
2. **業務層不可因 Provider 而分支**。Controller 與 ChatService 不得出現 `if ($provider === 'anthropic')` 之類的判斷，所有差異由 Provider 吸收。
3. **Provider 特有參數從 `config/llm.php` 讀取**，不放進 `ChatOptions`：
   - Ollama：`base_url`、`model`、`num_ctx`（沿用 `.env` 的 `OLLAMA_NUM_CTX`）、`think`、`timeout`
   - Anthropic：`api_key`、`model`、`default_max_tokens`、`version`、`timeout`
   - 模型名稱一律由 `.env` 設定，不寫死在程式碼中
4. **qwen3 思考模式預設關閉**（`think: false`），可由設定開啟。即使開啟，思考內容也不可混進 `ChatResult` 的 content。
5. **Anthropic 的 `max_tokens`**：`ChatOptions.maxTokens` 為 null 時，使用設定檔的 `default_max_tokens`。
6. **結束原因統一**：各家的結束原因（Ollama `done_reason`、Anthropic `stop_reason`）轉成專案自己的值，至少區分「正常結束」與「長度截斷」。
7. **Tolerant Reader**：解析回應時，不認識的欄位一律忽略；缺少必要欄位（例如回答內容）時丟出明確例外，不可回傳空字串。
8. **錯誤分類**：連線逾時、4xx、429、5xx、回應格式錯誤分別轉成專案自己的例外類型。例外訊息與 log 不可包含 API Key。
9. **預設 Provider 改為 `ollama`**。`fake` 保留給測試使用。
10. **允許修改 Ch01 的設計**，但每一處修改都必須在回報中列出內容與原因。

## 前置確認

1. 確認 `git status` 乾淨、tag `ch01-done` 存在；否則先停下來回報。
2. 建立分支 `ch02`。

## 實作項目

### 1. OllamaProvider

- 使用原生 `/api/chat`，不使用 OpenAI 相容端點
- `chat()`：`stream: false`，將回應轉成 `ChatResult`，usage 對應 `prompt_eval_count` 與 `eval_count`
- `stream()`：讀取 NDJSON，每行轉成 `StreamChunk`；最後一段（`done: true`）帶 usage 與結束原因
- 請求的 `options` 帶入 `num_ctx`；`think` 依設定帶入
- Timeout 要考慮首次載入模型需要十幾秒

### 2. AnthropicProvider

- system 訊息從 messages 中抽出，放到頂層 `system` 參數；若有多則，說明你的處理方式
- Header 帶入 API Key 與 API 版本（版本字串請依官方文件確認）
- `chat()`：將 content blocks 中的文字組合成 content，usage 對應 `input_tokens` 與 `output_tokens`
- `stream()`：解析 SSE 事件，文字來自 `content_block_delta`；usage 與結束原因要從串流過程中的事件累積，最後一段一起帶出

### 3. API

- `POST /api/ai/chat`：Request 包含 `provider`（選填，未填時使用預設值）、`messages`、`options`（選填）
- Request 使用 FormRequest 驗證：messages 不可為空、role 只能是 system / user / assistant
- `POST /api/ai/chat/stream`：以 SSE 回傳。每段送出 `{"delta": "..."}`，最後一段送出 `{"done": true, "usage": {...}, "finish_reason": "..."}`；發生錯誤時送出錯誤事件後結束
- 例外依類型轉成適當的 HTTP 狀態碼與 JSON 錯誤格式

### 4. Token 用量記錄

- 每次呼叫完成後寫入 log：provider、model、input / output tokens、結束原因、耗時
- 本章只寫 log，不建資料表

### 5. 手動驗證方式

提供一個 artisan 指令（例如 `php artisan llm:try {provider} [--stream]`），內建以下三輪對話，實際呼叫模型並印出回答與 usage：

1. user：請簡單介紹 RAG
2. assistant：（第一輪的實際回答）
3. user：那它跟微調有什麼不同？

System Prompt 需要求使用繁體中文（台灣用語）回答。

## 測試（全部必須通過）

自動化測試一律使用 `Http::fake()`，**不可呼叫真實 API**。

- OllamaProvider：送出的 request body 正確（messages、`num_ctx`、`think`）；回應正確轉成 `ChatResult`；NDJSON 串流可逐段取得，最後一段帶 usage
- AnthropicProvider：system 被抽到頂層、messages 中不再出現 system；`max_tokens` 預設值生效；SSE 串流可正確解析，最後一段帶 usage
- 結束原因：兩家的「長度截斷」都轉成同一個值
- 錯誤：逾時、429、5xx、缺少必要欄位時，丟出對應例外；例外訊息不含 API Key
- API：`/api/ai/chat` 以兩個不同 provider 送出同一段三輪對話，Controller 程式碼路徑相同、回應格式相同
- 驗證失敗（空 messages、未知 role）回傳 422

## 完成回報

做完以上內容就停止，不要進入 Ch03，不要 commit。請依序回報：

- **A.** 新增與修改的檔案清單，每個檔案用一句話說明責任
- **B.** 對 Ch01 設計的修改：改了什麼、為什麼 Ch01 的設計不夠用
- **C.** 同一段三輪對話，Ollama 與 Anthropic 各自實際送出的 request body（API Key 遮蔽）
- **D.** 兩家的回應與串流格式如何被統一成 `ChatResult` 與 `StreamChunk`
- **E.** 結束原因與錯誤類型的對應表
- **F.** 為什麼業務層不需要修改，用本章的測試舉例說明
- **G.** 測試執行結果
- **H.** 手動驗證結果：兩家實際回答的第三輪是否正確引用前兩輪；qwen3 關閉思考前後的 Token 數與耗時差異（若有測）
- **I.** 設計上有疑慮、或替我做了決定的地方
